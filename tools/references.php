<?php

declare(strict_types=1);

/**
 * 参照検索 (textDocument/references) の測定。
 *
 * 対象アプリのリソースクラス1つ1つについて、本物の phpactor language-server に
 * 「このクラスの参照を全部出せ」と要求し、返ってきた集合を2通りで検証する。
 *
 *   php tools/references.php /path/to/bear-app [--limit=N] [--only=正規表現]
 *
 * ── なぜ2通りか ────────────────────────────────────────────────────
 *
 * 期待集合を拡張と同じコードで作ると循環論法になる (PLAN.md §2.8 の自覚)。
 * そこでこの道具は拡張のクラスを一切使わず、素の正規表現だけで期待集合を作る。
 *
 *   (1) 文字列一致による期待集合との差分
 *       「URI文字列が同じなら参照」という素朴な規則で作った集合と機械diffする。
 *       拡張は「解決先のファイルが同じなら参照」で判定する (PLAN.md §2.11) ので、
 *       差分は原理的に出る。出た差分が全部「複数アプリ/曖昧」で説明できることを
 *       人が1件ずつ確かめるのが、この測定の主目的。
 *
 *   (2) 往復一致
 *       返ってきた参照の位置から textDocument/definition を投げ直し、起点の
 *       クラスへ戻ることを確かめる。期待集合を必要としない独立した検査。
 *
 * ── 期限について ──────────────────────────────────────────────────
 *
 * ReferencesHandler は10秒で window/showMessageRequest を出して人に聞き
 * (「まだ続けるか」)、30秒で打ち切る。返事をしないとサーバーが待ち続ける
 * (§2.9 で25分ハングしたのと同型)。この道具は必ず「最後まで続けろ」と答え、
 * 何回聞かれたかを報告する。聞かれた時点でその件は「遅い」ので記録に残す。
 */

$app = null;
$limit = 0;
$only = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, 8);
        continue;
    }
    if (str_starts_with($arg, '--only=')) {
        $only = substr($arg, 7);
        continue;
    }
    $app = $arg;
}
if ($app === null || !is_dir($app)) {
    fwrite(STDERR, "usage: php tools/references.php <bear-sunday-app-dir> [--limit=N] [--only=regex]\n");
    exit(1);
}
$app = (string) realpath($app);
$bin = dirname(__DIR__) . '/vendor/bin/phpactor';

// ★ この道具は Suzumaze\BearPhpactor\ のクラスを1つも使わない。
// 期待集合を拡張と同じコードで作ると循環論法になるため (PLAN.md §2.8)。
// 守られていることは測定の最後に class_exists(..., false) で機械的に確かめる。
// 文字列リテラルの収集にだけ tolerant-php-parser を借りる。コメント中の
// 'app://self/user' を数えないためで、素の正規表現ではそこで誤る。
require dirname(__DIR__) . '/vendor/autoload.php';

/** @return list<string> vendor を除く .php 全部 */
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

    return ['line' => substr_count($before, "\n"), 'character' => $offset - (int) strrpos("\n" . $before, "\n")];
}

/**
 * アプリの composer.json から psr-4 対応表 (autoload + autoload-dev) を読む。
 * プレフィックスは末尾 '\' 付き、ディレクトリは末尾 '/' 無しに正規化する。
 *
 * @return array<string, list<string>>
 */
function psr4Map(string $app): array
{
    $json = json_decode((string) file_get_contents($app . '/composer.json'), true);
    if (!is_array($json)) {
        return [];
    }
    $map = [];
    foreach (['autoload', 'autoload-dev'] as $section) {
        $psr4 = $json[$section]['psr-4'] ?? null;
        if (!is_array($psr4)) {
            continue;
        }
        foreach ($psr4 as $prefix => $dirs) {
            if (!is_string($prefix)) {
                continue;
            }
            $prefix = rtrim($prefix, '\\') . '\\';
            foreach ((array) $dirs as $dir) {
                if (!is_string($dir)) {
                    continue;
                }
                $map[$prefix][] = rtrim($dir, '/');
            }
        }
    }

    return $map;
}

/**
 * ファイルパスを psr-4 対応表で完全修飾名に変換する。対応が無ければ null。
 */
function fqnForFile(string $file, string $app, array $psr4): ?string
{
    $normalized = str_replace('\\', '/', $file);
    $best = null;
    $bestLen = -1;
    foreach ($psr4 as $prefix => $dirs) {
        foreach ($dirs as $dir) {
            $base = str_starts_with($dir, '/') ? rtrim($dir, '/') : $app . '/' . rtrim($dir, '/');
            if ($normalized !== $base && !str_starts_with($normalized, $base . '/')) {
                continue;
            }
            if (strlen($base) <= $bestLen) {
                continue;
            }
            $bestLen = strlen($base);
            $rest = trim(substr($normalized, strlen($base)), '/');
            $rest = substr($rest, 0, -4); // 末尾の .php を除く
            $best = $rest === '' ? $prefix : $prefix . str_replace('/', '\\', $rest);
        }
    }

    return $best;
}

/**
 * アプリ全体のクラス対応表 (完全修飾名 → ファイル) を psr-4 から作る。
 * 中間の基底クラスはリソースのディレクトリの外 (src/Domain/ など) にいるため、
 * Resource ディレクトリだけではなく phpFiles($app) 全体から作る。
 *
 * 同じ完全修飾名のファイルが2つあるとき (psr-4 が src/ と tests/ の両方を指す
 * 等) は先に来たほうを残す。phpFiles() は並べ替えるので src/ が tests/ より先に
 * 来て、本物のコードが勝つ。衝突は黙って捨てず、件数を $collisions に数える。
 *
 * @param int $collisions 同じ完全修飾名を2回以上見た回数 (参照で返す)
 *
 * @return array<string, string>
 */
function classMap(string $app, array $psr4, int &$collisions): array
{
    $map = [];
    foreach (phpFiles($app) as $file) {
        $fqn = fqnForFile($file, $app, $psr4);
        if ($fqn === null) {
            continue;
        }
        if (isset($map[$fqn])) {
            $collisions++;
            continue;
        }
        $map[$fqn] = $file;
    }

    return $map;
}

/**
 * ノードツリーから最初のクラス宣言を探す (文書順)。
 */
function firstClassDeclaration(Microsoft\PhpParser\Node $node): ?Microsoft\PhpParser\Node\Statement\ClassDeclaration
{
    if ($node instanceof Microsoft\PhpParser\Node\Statement\ClassDeclaration) {
        return $node;
    }
    foreach ($node->getChildNodes() as $child) {
        $found = firstClassDeclaration($child);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

/**
 * 完全修飾名が BEAR\Resource\ResourceObject に (間接的に) 行き着くか。
 * 継承の連鎖はクラス対応表でファイルを引き、ディスクから読んで辿る。
 * 深さの上限 (20) と循環の検出を持つ。拡張のクラスは使わない (循環論法の防止)。
 *
 * @param array<string, string> $classMap 完全修飾名 → ファイル
 * @param array<string, bool>   $memo     完全修飾名 → リソースか (メモ化)
 * @param list<string>          $chain    現在の連鎖 (循環の検出用)
 */
function extendsResourceObject(array $classMap, string $fqn, array &$memo, array $chain, int $depth, Microsoft\PhpParser\Parser $parser): bool
{
    if ($fqn === 'BEAR\Resource\ResourceObject') {
        return true;
    }
    if (isset($memo[$fqn])) {
        return $memo[$fqn];
    }
    if ($depth >= 20) {
        return false;
    }
    if (in_array($fqn, $chain, true)) {
        return false;
    }
    $file = $classMap[$fqn] ?? null;
    if ($file === null) {
        return false;
    }
    $text = (string) file_get_contents($file);
    $class = firstClassDeclaration($parser->parseSourceFile($text));
    if ($class === null || $class->classBaseClause === null || $class->classBaseClause->baseClass === null) {
        return false;
    }
    $parent = $class->classBaseClause->baseClass->getResolvedName();
    if ($parent === null) {
        return false;
    }
    $result = extendsResourceObject($classMap, (string) $parent, $memo, [...$chain, $fqn], $depth + 1, $parser);
    $memo[$fqn] = $result;

    return $result;
}

/**
 * リソースクラスの宣言を集める。
 *
 * クラス宣言は構文解析で探し (拡張と同じ「最初のクラス宣言」の規則)、継承の
 * 連鎖は psr-4 対応表でファイルを引き、ディスクから読んで辿る。拡張と同じ規則
 * を独立に書き下したもの (共有はしない)。docblock 由来の誤検出は構文解析で
 * 拾わない。起点のクラスは解析済みの宣言から判定する (完全修飾名で対応表を
 * 引き直すと、同じ完全修飾名の別ファイルに上書きされて本物が落ちる)。
 *
 * @param int $collisions classMap() が数えた完全修飾名の衝突件数 (参照で返す)
 *
 * @return list<array{file: string, name: string, offset: int, uri: string}>
 */
function resourceClasses(string $app, int &$collisions): array
{
    $psr4 = psr4Map($app);
    $classMap = classMap($app, $psr4, $collisions);
    $parser = new Microsoft\PhpParser\Parser();
    $memo = [];
    $out = [];
    foreach (phpFiles($app) as $file) {
        $normalized = str_replace('\\', '/', $file);
        if (!preg_match('#/Resource/(App|Page)/#', $normalized, $m)) {
            continue;
        }
        $text = (string) file_get_contents($file);
        $class = firstClassDeclaration($parser->parseSourceFile($text));
        if ($class === null || $class->name === null) {
            continue;
        }
        $fqn = fqnForFile($file, $app, $psr4);
        if ($fqn === null) {
            continue;
        }
        // 起点は解析済みの宣言から。完全修飾名で対応表を引き直すと、同じ完全
        // 修飾名のファイルが2つあるとき (psr-4 が src/ と tests/ の両方を指す
        // 等) あとから来たほうに上書きされ、本物のリソースが黙って落ちる。
        // 対応表を引くのは親をたどるときだけで十分 (親は別ファイルにある)。
        if ($class->classBaseClause === null || $class->classBaseClause->baseClass === null) {
            continue;
        }
        $parent = $class->classBaseClause->baseClass->getResolvedName();
        if ($parent === null) {
            continue;
        }
        if (!extendsResourceObject($classMap, (string) $parent, $memo, [], 0, $parser)) {
            continue;
        }

        // Resource/App/Cache/Author.php → app://self/cache/author
        $at = strrpos($normalized, '/Resource/' . $m[1] . '/');
        $rest = substr($normalized, $at + strlen('/Resource/' . $m[1] . '/'), -4);
        $path = implode('/', array_map('lcfirst', explode('/', $rest)));

        $out[] = [
            'file' => $file,
            'name' => $class->name->getText($text),
            'offset' => $class->name->getStartPosition(),
            'uri' => strtolower($m[1]) . '://self/' . $path,
        ];
    }

    return $out;
}

/**
 * URIを比較用に正規化する。パスの各セグメントをパスカルケースに寄せて、
 * blog-posting と blogPosting を同一視する (拡張の ResourceUri と同じ規則を
 * 独立に書き下したもの。共有はしない)。
 * リソースURIでなければ null。
 */
function canonicalUri(string $value): ?string
{
    $value = (string) preg_replace('/[{?#].*$/s', '', $value);
    if (!preg_match('#^(app|page)://([^/]+)/(.+)$#', $value, $m)) {
        return null;
    }
    $segments = array_filter(explode('/', $m[3]), static fn (string $v): bool => $v !== '');
    if ($segments === []) {
        return null;
    }
    $pascal = array_map(
        static fn (string $s): string => implode('', array_map('ucfirst', explode('-', $s))),
        $segments
    );

    return sprintf('%s://%s/%s', $m[1], $m[2], implode('/', $pascal));
}

/**
 * URI文字列を含む文字列リテラルを全部集める。
 *
 * @return array<string, list<array{file: string, start: int, end: int, text: string}>> 正規化URI → サイト一覧
 */
function uriSites(string $app): array
{
    $parser = new Microsoft\PhpParser\Parser();
    $sites = [];
    foreach (phpFiles($app) as $file) {
        $text = (string) file_get_contents($file);
        if (!str_contains($text, 'app://') && !str_contains($text, 'page://')) {
            continue;
        }
        foreach ($parser->parseSourceFile($text)->getDescendantNodes() as $node) {
            if (!$node instanceof Microsoft\PhpParser\Node\StringLiteral) {
                continue;
            }
            $canonical = canonicalUri($node->getStringContentsText());
            if ($canonical === null) {
                continue;
            }
            $sites[$canonical][] = [
                'file' => $file,
                'start' => $node->getStartPosition(),
                'end' => $node->getEndPosition(),
                'text' => $node->getStringContentsText(),
            ];
        }
    }

    return $sites;
}

// ---- LSP セッション（tools/coverage.php と同じ組み立て） ------------------

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

/** 1メッセージ読む。array=メッセージ / false=期限切れ / null=EOF */
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

$slowPrompts = 0;
$readUntilId = function (int $id, float $timeout = 60.0) use ($read, $send, &$slowPrompts) {
    $deadline = microtime(true) + $timeout;
    for ($i = 0; $i < 20000; $i++) {
        $msg = $read($deadline);
        if ($msg === false || $msg === null) {
            return $msg;
        }

        // サーバー -> クライアントの要求。返事をしないと相手が止まる
        if (isset($msg['id'], $msg['method'])) {
            $reply = null;
            if ($msg['method'] === 'window/showMessageRequest') {
                $actions = $msg['params']['actions'] ?? [];
                $text = (string) ($msg['params']['message'] ?? '');
                if (str_contains($text, 'Finding references is taking a while')) {
                    // 「打ち切る」ではなく「最後まで続けろ」と答える。
                    // 既定の先頭候補は "No, show me what you got" で、黙って
                    // 結果を切り落とすため測定にならない。
                    $slowPrompts++;
                    $reply = $actions[count($actions) - 1] ?? null;
                } else {
                    $reply = $actions[0] ?? null;
                }
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
    'processId' => getmypid(),
    'rootUri' => 'file://' . $app,
    'capabilities' => ['window' => ['workDoneProgress' => true]],
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

    // サーバー -> クライアントの要求には返事を返す。window/workDoneProgress/create
    // を無視すると、プログレストークンが作られずインデックスが始まらない
    // (サーバーは返事を待って止まる)。
    if (isset($msg['id'], $msg['method'])) {
        $send(['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => null]);
        continue;
    }

    // 完了の通知先はクライアントの capabilities で変わる。
    // workDoneProgress を宣言していると $/progress の kind:end に来て、
    // 宣言していないと window/showMessage に落ちる。両方を見ないと、
    // 片方だけ見ていた最初の版のように「900秒待って諦める」ことになる。
    $method = (string) ($msg['method'] ?? '');
    $text = '';
    if ($method === 'window/showMessage') {
        $text = (string) ($msg['params']['message'] ?? '');
    } elseif ($method === '$/progress') {
        $text = (string) ($msg['params']['value']['message'] ?? '');
    }
    if (str_contains($text, 'Done indexing')) {
        break;
    }
}
fwrite(STDERR, sprintf("indexed in %.1fs\n", microtime(true) - $indexStart));

// ---- 測定 --------------------------------------------------------------

$collisions = 0;
$classes = resourceClasses($app, $collisions);
if ($only !== null) {
    $classes = array_values(array_filter($classes, static fn (array $c): bool => (bool) preg_match('#' . $only . '#', $c['file'])));
}
if ($limit > 0) {
    $classes = array_slice($classes, 0, $limit);
}
$sites = uriSites($app);

$id = 100;
$opened = [];
$open = function (string $file) use (&$opened, $send): string {
    $uri = 'file://' . $file;
    if (!isset($opened[$file])) {
        $send(['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
            'textDocument' => ['uri' => $uri, 'languageId' => 'php', 'version' => 1,
                'text' => (string) file_get_contents($file)],
        ]]);
        $opened[$file] = true;
    }

    return $uri;
};

$rel = static fn (string $f): string => str_replace($app . '/', '', $f);
$report = [];
$totals = ['classes' => 0, 'returned' => 0, 'agreed' => 0, 'extra' => 0, 'missing' => 0,
    'roundtrip_ok' => 0, 'roundtrip_ng' => 0, 'timeout' => 0, 'ms' => []];

foreach ($classes as $class) {
    $file = $class['file'];
    $text = (string) file_get_contents($file);
    $uri = $open($file);
    $totals['classes']++;

    $send(['jsonrpc' => '2.0', 'id' => ++$id, 'method' => 'textDocument/references', 'params' => [
        'textDocument' => ['uri' => $uri],
        'position' => toPosition($text, $class['offset']),
        'context' => ['includeDeclaration' => false],
    ]]);
    $t0 = microtime(true);
    $res = $readUntilId($id);
    $elapsed = (microtime(true) - $t0) * 1000;

    if ($res === null) {
        fwrite(STDERR, "エラー: 言語サーバーが落ちた ({$rel($file)})\n");
        break;
    }
    if ($res === false) {
        $totals['timeout']++;
        $report[] = ['class' => $rel($file), 'note' => '60秒無応答'];
        continue;
    }
    $totals['ms'][] = $elapsed;

    $returned = [];
    foreach (($res['result'] ?? []) as $loc) {
        $path = (string) preg_replace('#^file://#', '', (string) $loc['uri']);
        $returned[sprintf('%s:%d:%d', $path, $loc['range']['start']['line'], $loc['range']['start']['character'])] = $loc;
    }
    $totals['returned'] += count($returned);

    // (1) 文字列一致の期待集合
    $canonical = canonicalUri($class['uri']);
    $expected = [];
    foreach ($sites[$canonical] ?? [] as $site) {
        $siteText = (string) file_get_contents($site['file']);
        $p = toPosition($siteText, $site['start']);
        $expected[sprintf('%s:%d:%d', $site['file'], $p['line'], $p['character'])] = $site;
    }

    $extra = array_diff_key($returned, $expected);
    $missing = array_diff_key($expected, $returned);
    $totals['agreed'] += count(array_intersect_key($returned, $expected));
    $totals['extra'] += count($extra);
    $totals['missing'] += count($missing);

    // (2) 往復一致: 返ってきた位置から定義ジャンプすると起点へ戻るか
    $roundtripNg = [];
    foreach ($returned as $key => $loc) {
        $path = (string) preg_replace('#^file://#', '', (string) $loc['uri']);
        if (!is_file($path)) {
            $roundtripNg[] = $key . ' [ファイルが無い]';
            continue;
        }
        $siteText = (string) file_get_contents($path);
        $siteUri = $open($path);
        // 開きクォートの内側を狙う（返る範囲はクォート込みのはず）
        $send(['jsonrpc' => '2.0', 'id' => ++$id, 'method' => 'textDocument/definition', 'params' => [
            'textDocument' => ['uri' => $siteUri],
            'position' => ['line' => $loc['range']['start']['line'],
                'character' => $loc['range']['start']['character'] + 1],
        ]]);
        $back = $readUntilId($id, 20.0);
        if (!is_array($back)) {
            $roundtripNg[] = $key . ' [定義ジャンプが無応答]';
            continue;
        }
        $r = $back['result'] ?? null;
        $target = is_array($r) ? ($r['uri'] ?? ($r[0]['uri'] ?? null)) : null;
        if ($target === 'file://' . $file) {
            $totals['roundtrip_ok']++;
        } else {
            $totals['roundtrip_ng']++;
            $roundtripNg[] = $key . ' → ' . ($target === null ? 'どこへも飛ばない' : $rel((string) preg_replace('#^file://#', '', (string) $target)));
        }
    }

    if ($extra !== [] || $missing !== [] || $roundtripNg !== []) {
        $report[] = [
            'class' => $rel($file),
            'uri' => $class['uri'],
            'returned' => count($returned),
            'extra' => array_map($rel, array_keys($extra)),
            'missing' => array_map($rel, array_keys($missing)),
            'roundtrip' => array_map($rel, $roundtripNg),
        ];
    }
}

$send(['jsonrpc' => '2.0', 'id' => 9999, 'method' => 'shutdown', 'params' => new stdClass()]);
$readUntilId(9999, 10.0);
foreach ($pipes as $p) {
    @fclose($p);
}
proc_terminate($proc);

// ---- 報告 --------------------------------------------------------------

$ms = $totals['ms'];
sort($ms);
$q = static fn (array $xs, float $p): float => $xs === [] ? 0.0 : $xs[(int) min(count($xs) - 1, floor(count($xs) * $p))];

echo "\n対象: {$app}\n";
echo str_repeat('=', 76), "\n";
printf("リソースクラス                       %4d\n", $totals['classes']);
printf("psr-4 完全修飾名の衝突 (先勝ち)      %4d\n", $collisions);
printf("返ってきた参照の総数                 %4d\n", $totals['returned']);
printf("  うち文字列一致の期待集合と一致     %4d\n", $totals['agreed']);
printf("  期待集合に無いのに返った (超過)    %4d\n", $totals['extra']);
printf("  期待集合にあるのに返らない (不足)  %4d\n", $totals['missing']);
printf("往復一致 (参照→定義で起点へ戻る)   %4d ok / %d ng\n", $totals['roundtrip_ok'], $totals['roundtrip_ng']);
printf("60秒無応答                           %4d\n", $totals['timeout']);
printf("10秒プロンプト (遅い要求)            %4d\n", $slowPrompts);
printf("1要求あたり  p50 %.1f ms  p95 %.1f ms  max %.1f ms\n", $q($ms, 0.5), $q($ms, 0.95), $ms === [] ? 0 : max($ms));
echo str_repeat('-', 76), "\n";
echo "  超過/不足は「文字列が同じなら参照」という素朴な規則との差。0が正解ではない。\n";
echo "  差分は1件ずつ人が読むこと (PLAN.md §2.11: 判定は解決先のファイルで行う)。\n";

foreach ($report as $r) {
    echo "\n", $r['class'];
    if (isset($r['uri'])) {
        printf("  (%s, %d件返った)", $r['uri'], $r['returned']);
    }
    echo "\n";
    if (isset($r['note'])) {
        echo "  ! ", $r['note'], "\n";
        continue;
    }
    foreach ($r['extra'] as $e) {
        echo "  + 超過  ", $e, "\n";
    }
    foreach ($r['missing'] as $m) {
        echo "  - 不足  ", $m, "\n";
    }
    foreach ($r['roundtrip'] as $t) {
        echo "  ! 往復  ", $t, "\n";
    }
}
echo "\n";

// ★ 循環論法の防止が守られたことの機械的な確認。
// 拡張のクラスに1つでも触れていたら、この測定の独立性は失われている。
$leaked = array_values(array_filter(
    get_declared_classes(),
    static fn (string $c): bool => str_starts_with($c, 'Suzumaze\\BearPhpactor\\')
));
if ($leaked !== []) {
    fwrite(STDERR, "★ この測定は拡張のクラスを読み込んでいる。期待集合が独立していない:\n  "
        . implode("\n  ", $leaked) . "\n");
    exit(1);
}
