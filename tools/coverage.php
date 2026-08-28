<?php

declare(strict_types=1);

/**
 * 実アプリに対する到達率の測定。
 *
 * 対象アプリのソースを走査して「この拡張が答えるべき場所」を全部数え上げ、
 * 本物の phpactor language-server に textDocument/definition を投げて、
 * 何件に答えられたかを報告する。クラス宣言名 (規約ジャンプ) だけは
 * textDocument/typeDefinition に載せ替えた (PLAN.md §2.6 の②の退避先) ため、
 * その種類だけ typeDefinition を投げる。
 *
 *   php tools/coverage.php /path/to/bear-app
 *
 * 出力は種類ごとの ヒット/全体 と、外した場所の一覧（file:line と対象の文字列）。
 */

$app = $argv[1] ?? null;
if ($app === null || !is_dir($app)) {
    fwrite(STDERR, "usage: php tools/coverage.php <bear-sunday-app-dir>\n");
    exit(1);
}
$app = (string) realpath($app);
require dirname(__DIR__) . '/vendor/autoload.php';
$bin = dirname(__DIR__) . '/vendor/bin/phpactor';

/** 対象アプリの PHP ファイルを全部集める（vendor は除く） */
function phpFiles(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (str_contains($p, '/vendor/') || !str_ends_with($p, '.php')) {
            continue;
        }
        $out[] = $p;
    }
    sort($out);

    return $out;
}

/** バイトオフセットを LSP の 0起点 行/桁 に変換する */
function toPosition(string $text, int $offset): array
{
    $before = substr($text, 0, $offset);
    $line = substr_count($before, "\n");
    $lineStart = (int) strrpos("\n" . $before, "\n");

    return ['line' => $line, 'character' => $offset - $lineStart];
}

/**
 * この拡張が答えるべき場所を数え上げる。
 *
 * 正規表現ではなく構文解析で集める。コメントの中に書かれた 'app://self/user' のような
 * 文字列を数えてしまうと、拡張が正しく「飛ばない」と判断した場所を「外した」と誤って
 * 数えることになるため（実際に一度そうなった）。
 * ただし @Query だけは docblock の中にあるのが正しい書き方なので、そこだけトークンを見る。
 *
 * 種類ごとに [file, offset, 対象文字列] を返す。
 */
function collectSites(string $app): array
{
    $parser = new Microsoft\PhpParser\Parser();
    $sites = ['resource_uri' => [], 'resource_uri_other_host' => [], 'sql_query' => [], 'route_path' => [], 'schema_class' => []];

    foreach (phpFiles($app) as $file) {
        $text = (string) file_get_contents($file);
        $root = $parser->parseSourceFile($text);
        $isRoute = basename($file) === 'aura.route.php';
        $inResourceDir = (bool) preg_match('#/Resource/(App|Page)/#', str_replace('\\', '/', $file));

        foreach ($root->getDescendantNodes() as $node) {
            // クラス宣言名（規約による JSON Schema ジャンプ）
            if ($inResourceDir && $node instanceof Microsoft\PhpParser\Node\Statement\ClassDeclaration) {
                $name = $node->name;
                if ($name !== null) {
                    $sites['schema_class'][] = [$file, $name->getStartPosition(), (string) $name->getText($text)];
                }
                continue;
            }

            if (!$node instanceof Microsoft\PhpParser\Node\StringLiteral) {
                continue;
            }

            $value = $node->getStringContentsText();
            $offset = $node->getStartPosition() + 1;   // 開きクォートの内側

            // self 以外のホストも数える。アプリは ImportApp で別パッケージを
            // 別ホストに割り当てられる (例: app://tags/ -> Acme\Tags)。
            // self だけ数えていたせいで、対応漏れが測定に現れていなかった。
            if (preg_match('#^(app|page)://([a-z0-9_-]+)/#', $value, $hm)) {
                $bucket = $hm[2] === 'self' ? 'resource_uri' : 'resource_uri_other_host';
                $sites[$bucket][] = [$file, $offset, $value];
                continue;
            }

            // #[DbQuery('name')] の第1引数
            // #[DbQuery('name')] の第1引数だけ。type: 'row' のような名前付き引数は対象外
            // （拡張も第1引数しか見ないので、ここで数えると外したことにされてしまう）
            $arg = $node->getParent();
            $list = $arg?->getParent();
            $attr = $list?->getParent();
            if ($attr instanceof Microsoft\PhpParser\Node\Attribute
                && str_contains((string) $attr->getText(), 'DbQuery')
                && $arg instanceof Microsoft\PhpParser\Node\Expression\ArgumentExpression
                && $arg->name === null
                && ($list?->children[0] ?? null) === $arg) {
                $sites['sql_query'][] = [$file, $offset, $value];
                continue;
            }

            if ($isRoute && str_starts_with($value, '/')) {
                $sites['route_path'][] = [$file, $offset, $value];
            }
        }

        // @Query("name") は docblock の中にあるのが正しい
        if (preg_match_all('#@Query\s*\(\s*[\'"]([a-z0-9_]+)[\'"]#i', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$val, $off]) {
                $sites['sql_query'][] = [$file, $off, $val];
            }
        }
    }

    return $sites;
}

// ---- LSP セッション ----------------------------------------------------

$proc = proc_open([$bin, 'language-server'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $app);
if (!is_resource($proc)) {
    fwrite(STDERR, "could not start phpactor\n");
    exit(1);
}
stream_set_blocking($pipes[1], false);

$send = function (array $msg) use ($pipes): void {
    $body = json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    fwrite($pipes[0], "Content-Length: " . strlen($body) . "\r\n\r\n" . $body);
    fflush($pipes[0]);
};

/**
 * 1メッセージ読む。返り値は3種類:
 *   array  … メッセージ
 *   false  … 期限切れ (サーバーが黙っている)
 *   null   … EOF (サーバーが死んだ)
 *
 * 期限を持たないと、サーバーが応答しなくなったとき永久に待つ。
 * 実際に実アプリの測定が25分間ブロックした。
 */
$read = function (float $deadline) use ($pipes) {
    $waitFor = function () use ($pipes, $deadline): bool {
        $remain = $deadline - microtime(true);
        if ($remain <= 0) {
            return false;
        }
        $r = [$pipes[1]];
        $w = $x = null;

        return @stream_select($r, $w, $x, 0, (int) min($remain, 0.5) * 1000000 + 1000) > 0;
    };

    $header = '';
    while (!str_contains($header, "\r\n\r\n")) {
        if (microtime(true) > $deadline) {
            return false;
        }
        if (!$waitFor()) {
            continue;
        }
        $c = fread($pipes[1], 1);
        if ($c === false) {
            return null;
        }
        if ($c === '') {
            if (feof($pipes[1])) {
                return null;
            }
            continue;
        }
        $header .= $c;
    }

    preg_match('/Content-Length: (\d+)/i', $header, $m);
    $len = (int) $m[1];
    $body = '';
    while (strlen($body) < $len) {
        if (microtime(true) > $deadline) {
            return false;
        }
        if (!$waitFor()) {
            continue;
        }
        $c = fread($pipes[1], $len - strlen($body));
        if ($c === false) {
            return null;
        }
        if ($c === '' && feof($pipes[1])) {
            return null;
        }
        $body .= $c;
    }

    return json_decode($body, true);
};

/**
 * id が一致する応答を待つ。false=期限切れ、null=EOF。
 *
 * 途中でサーバーからクライアントへの「要求」(id と method の両方を持つ) が来たら
 * 返事をする。返事をしないとサーバーはそこで待ち続ける。
 *
 * とくに window/showMessageRequest が重要で、phpactor は定義ジャンプの候補が
 * 複数あるとき Location の配列を返さず、この選択ダイアログに載せてくる。
 * 実際これに返事をしなかったせいで測定が15秒無応答になった。
 */
$pickerCount = 0;
$readUntilId = function (int $id, float $timeout = 15.0) use ($read, $send, &$pickerCount) {
    $deadline = microtime(true) + $timeout;
    for ($i = 0; $i < 2000; $i++) {
        $msg = $read($deadline);
        if ($msg === false || $msg === null) {
            return $msg;
        }

        // サーバー -> クライアントの要求。返事をしないと相手が止まる
        if (isset($msg['id'], $msg['method'])) {
            $reply = null;
            if ($msg['method'] === 'window/showMessageRequest') {
                $actions = $msg['params']['actions'] ?? [];
                $reply = $actions[0] ?? null;
                $pickerCount++;
            }
            $send(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => $reply]);
            continue;
        }

        if (($msg['id'] ?? null) === $id) {
            return $msg;
        }
    }

    return false;
};

$send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
    'processId' => getmypid(), 'rootUri' => 'file://' . $app, 'capabilities' => new stdClass(),
]]);
$readUntilId(1);
$send(['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => new stdClass()]);

fwrite(STDERR, "indexing...\n");
$indexDeadline = microtime(true) + 900.0;
$indexStart = microtime(true);
while (true) {
    $msg = $read($indexDeadline);
    if ($msg === false) {
        fwrite(STDERR, "警告: インデックス完了の通知が900秒来なかった。そのまま測定に進む\n");
        break;
    }
    if ($msg === null) {
        fwrite(STDERR, "エラー: 言語サーバーが落ちた\n");
        exit(1);
    }
    if (($msg['method'] ?? '') === 'window/showMessage'
        && str_contains($msg['params']['message'] ?? '', 'Done indexing')) {
        break;
    }
}
fwrite(STDERR, sprintf("indexed in %.1fs\n", microtime(true) - $indexStart));

// ---- 測定 --------------------------------------------------------------

$sites = collectSites($app);
$id = 100;
$opened = [];
$results = [];

foreach ($sites as $kind => $list) {
    $results[$kind] = ['hit' => 0, 'miss' => [], 'timeout' => 0, 'picker' => 0, 'ms' => [], 'cold' => []];
    foreach ($list as [$file, $offset, $value]) {
        $text = (string) file_get_contents($file);
        $uri = 'file://' . $file;
        if (!isset($opened[$file])) {
            $send(['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => $uri, 'languageId' => 'php', 'version' => 1, 'text' => $text],
            ]]);
            $opened[$file] = true;
            $isFirstInFile = true;
        } else {
            $isFirstInFile = false;
        }
        $send(['jsonrpc' => '2.0', 'id' => ++$id, 'method' => $kind === 'schema_class' ? 'textDocument/typeDefinition' : 'textDocument/definition', 'params' => [
            'textDocument' => ['uri' => $uri],
            'position' => toPosition($text, $offset),
        ]]);
        $t0 = microtime(true);
        $pickerBefore = $pickerCount;
        $res = $readUntilId($id);
        $elapsed = (microtime(true) - $t0) * 1000;

        $pos = toPosition($text, $offset);
        $where = sprintf('%s:%d  %s', str_replace($app . '/', '', $file), $pos['line'] + 1, $value);

        if ($res === null) {
            fwrite(STDERR, "エラー: 言語サーバーが落ちた ({$where})\n");
            break 2;
        }
        if ($res === false) {
            $results[$kind]['timeout']++;
            $results[$kind]['miss'][] = $where . '  [15秒無応答]';
            continue;
        }

        if ($isFirstInFile) {
            $results[$kind]['cold'][] = $elapsed;
        } else {
            $results[$kind]['ms'][] = $elapsed;
        }
        $result = $res['result'] ?? null;
        $target = is_array($result) ? ($result['uri'] ?? ($result[0]['uri'] ?? null)) : null;

        // 選択ダイアログが出た = 候補が複数あった = 見つかっている
        if ($target === null && $pickerCount > $pickerBefore) {
            $results[$kind]['hit']++;
            $results[$kind]['picker']++;
            continue;
        }

        // クラス宣言名は「答えが返ったか」では数えない。
        //
        // 当拡張が降りても、組込みロケータが「そのクラス自身」を返す。それを
        // ヒットに数えていたため BEAR.Kata で 85/85 (100%) と出ていたが、
        // 85件のうち38件は PHPUnit のテストクラスで、当拡張は正しく降りていた
        // (実測: tests/Resource/App/ArticleTest.php -> 自分自身)。
        // スキーマファイルは18個しか無いので、100% は実態を表していなかった。
        // この種類だけ「答えが var/json_schema か var/json_validate の下にある」
        // ことを要求する。数字は下がるが、下がったほうが実態。
        if ($kind === 'schema_class'
            && $target !== null
            && !preg_match('#/var/json_(schema|validate)/#', (string) $target)) {
            $results[$kind]['miss'][] = $where . '  [当拡張は答えていない]';
            continue;
        }

        if ($target === null) {
            $results[$kind]['miss'][] = $where;
        } else {
            $results[$kind]['hit']++;
        }
    }
}

$send(['jsonrpc' => '2.0', 'id' => 9999, 'method' => 'shutdown', 'params' => new stdClass()]);
$readUntilId(9999);
foreach ($pipes as $p) {
    @fclose($p);
}
proc_terminate($proc);

// ---- 報告 --------------------------------------------------------------

$labels = [
    'resource_uri' => 'リソースURI(self)   → クラス',
    'resource_uri_other_host' => 'リソースURI(他ホスト) → クラス',
    'sql_query' => 'クエリ名           → SQLファイル',
    'route_path' => 'ルートパス         → Pageクラス',
    'schema_class' => 'クラス宣言名       → JSON Schema (型定義)',
];

echo "\n対象: {$app}\n";
echo str_repeat('=', 72), "\n";
$totalHit = $totalAll = 0;
foreach ($results as $kind => $r) {
    $all = $r['hit'] + count($r['miss']);
    $totalHit += $r['hit'];
    $totalAll += $all;
    $pct = $all === 0 ? '   -' : sprintf('%3d%%', (int) round($r['hit'] / $all * 100));
    $ms = $r['ms'];
    sort($ms);
    $p = static function (array $xs, float $q): float {
        return $xs === [] ? 0.0 : $xs[(int) min(count($xs) - 1, floor(count($xs) * $q))];
    };
    $timing = '';
    printf("%-34s %3d / %3d  %s%s\n", $labels[$kind] ?? $kind, $r['hit'], $all, $pct, $timing);
}
echo str_repeat('-', 72), "\n";
$allMs = [];
$allCold = [];
foreach ($results as $r) {
    foreach ($r['ms'] as $m) {
        $allMs[] = $m;
    }
    foreach ($r['cold'] as $m) {
        $allCold[] = $m;
    }
}
sort($allMs);
sort($allCold);
$q = static function (array $xs, float $qq): float {
    return $xs === [] ? 0.0 : $xs[(int) min(count($xs) - 1, floor(count($xs) * $qq))];
};
printf("%-34s %3d / %3d  %3d%%\n", '合計', $totalHit, $totalAll,
    $totalAll === 0 ? 0 : (int) round($totalHit / $totalAll * 100));
printf("\n  速度はこの道具では測らない (種類ごとにファイルの開かれ方が違い公平に比べられない)。\n");
printf("  tools/latency.php を使うこと。\n");

if ($pickerCount > 0) {
    printf("\n候補が複数あり選択ダイアログになったもの: %d件\n", $pickerCount);
}

$timeouts = array_sum(array_column($results, 'timeout'));
if ($timeouts > 0) {
    printf("\n★ 15秒以内に応答が無かったリクエスト: %d件\n", $timeouts);
}

foreach ($results as $kind => $r) {
    if ($r['miss'] === []) {
        continue;
    }
    echo "\n外した場所 — ", ($labels[$kind] ?? $kind), "\n";
    foreach ($r['miss'] as $m) {
        echo "  ", $m, "\n";
    }
}
echo "\n";
