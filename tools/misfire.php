<?php

declare(strict_types=1);

/**
 * Measure whether the extension fires where it must not.
 *
 * tools/coverage.php measures whether the extension jumps to the right place
 * when it should jump. This tool measures the opposite: whether it jumps when
 * it must stay quiet. The "must stay quiet" places are the whole rest of the
 * file, which cannot be probed exhaustively, so the probes are limited to
 * the places where misfires actually happened before: one byte outside
 * string literals and class declaration names.
 *
 * For every site that coverage.php's collectSites() gathers, a probe is
 * sent one byte before the start (the opening quote or the name start);
 * string literals also get one probe one byte after the end. Class
 * declaration names get no after probe (see below). The probes must not
 * react. The opening quote itself and the closing quote itself are
 * intentionally reactive (the guard in StringLiteralAtOffset lets the
 * cursor sit on them) and are not probed.
 *
 * Class declaration names have no after probe. The position right after the
 * last character (getEndPosition()) is where editors treat the cursor as
 * "on the word" (F12 works there), so firing there is normal, not a misfire;
 * one byte further out (end + 1) the parser returns a different node, not
 * the ClassDeclaration, so the extension's guard is never reached. There is
 * no position on the back side where a misfire could occur. String literals
 * are different: the closing quote is a delimiter, not an identifier, so
 * their after probe stays right after the closing quote. The two are
 * asymmetric on purpose — do not "align" them.
 *
 * One extra probe kind checks that textDocument/definition (F12) on a class
 * declaration name does not land on a schema file. The convention jump used
 * to live on F12 and was moved to typeDefinition (PLAN.md §2.6); this checks
 * it has not moved back. That probe sits on the name itself, not on a
 * boundary.
 *
 * The judgment is not "no response means pass": the built-in phpactor
 * legitimately answers (for a class name, its own file). A probe is a false
 * positive only when the answer lands on a destination this extension could
 * produce for that feature:
 *
 *   class name surroundings    -> var/json_schema/ or var/json_validate/
 *   resource URI surroundings  -> Resource/App/ or Resource/Page/ *.php
 *   SQL query name surroundings -> the SQL file directory (var/db/sql/)
 *   route path surroundings    -> Resource/Page/ *.php
 *
 * The signatures are applied per feature, never merged: the built-in's
 * legitimate answer for a class name is the class's own file, which lives
 * under Resource/App/ — applying the URI signature to class-name probes
 * would report correct behavior as a misfire.
 *
 * Completion (textDocument/completion) misfires are NOT measured here:
 * judging them requires inspecting the candidate list, which is a separate
 * tool. The report says so explicitly.
 *
 *   php tools/misfire.php /path/to/bear-app
 *
 * Output: the site population and the probe total (so a 0 count is
 * distinguishable from a broken collection), per-kind counts of false
 * positive / no problem / picker, and one detail block per false positive
 * (source file:line, which edge, where it landed). Destinations are
 * realpath'd before comparison (macOS /tmp is a symlink to /private/tmp).
 *
 * A false positive here is a fact, not a verdict: the signature may be too
 * strict. The report does not judge, the reader does.
 */

$app = $argv[1] ?? null;
if ($app === null || !is_dir($app)) {
    fwrite(STDERR, "usage: php tools/misfire.php <bear-sunday-app-dir>\n");
    exit(1);
}
$app = (string) realpath($app);
require dirname(__DIR__) . '/vendor/autoload.php';
$bin = dirname(__DIR__) . '/vendor/bin/phpactor';

/** All PHP files of the target app (vendor excluded). */
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

/** Byte offset to a 0-based LSP line/character pair. */
function toPosition(string $text, int $offset): array
{
    $before = substr($text, 0, $offset);
    $line = substr_count($before, "\n");
    $lineStart = (int) strrpos("\n" . $before, "\n");

    return ['line' => $line, 'character' => $offset - $lineStart];
}

/**
 * Collect every site coverage.php's collectSites() gathers, with the exact
 * start and end positions taken from the parser nodes — never derived by
 * subtracting from an inside offset, which is where off-by-one mistakes
 * creep in.
 *
 * For a string literal, start is the opening quote and end is the position
 * after the closing quote. For a class declaration name, start is the first
 * byte of the name and end is the position after the last byte. The probes
 * are start - 1 and end, except for class names, which have no after probe:
 * the position right after the last character is where editors treat the
 * cursor as "on the word" (firing there is normal), and one byte further
 * out the parser returns a different node, so the extension's guard is
 * never reached.
 *
 * @return array<string, list<array{0: string, 1: int, 2: int, 3: string, 4?: string}>>
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
            // Class declaration name (convention JSON Schema jump).
            if ($inResourceDir && $node instanceof Microsoft\PhpParser\Node\Statement\ClassDeclaration) {
                $name = $node->name;
                if ($name !== null) {
                    // The namespace is captured too: the schema convention
                    // derives its candidates from the \Resource\App\ or
                    // \Resource\Page\ sub-namespace, not from the file path.
                    $ns = $node->getNamespaceDefinition();
                    $nsText = $ns !== null && $ns->name instanceof Microsoft\PhpParser\Node\QualifiedName
                        ? (string) $ns->name->getText($text)
                        : '';
                    $sites['schema_class'][] = [
                        $file,
                        $name->getStartPosition(),
                        $name->getEndPosition(),
                        (string) $name->getText($text),
                        $nsText,
                    ];
                }
                continue;
            }

            if (!$node instanceof Microsoft\PhpParser\Node\StringLiteral) {
                continue;
            }

            $start = $node->getStartPosition();
            $end = $node->getEndPosition();
            $value = $node->getStringContentsText();

            // Resource URIs (self and other hosts; ImportApp assigns other
            // hosts to other packages).
            if (preg_match('#^(app|page)://([a-z0-9_-]+)/#', $value, $hm)) {
                $bucket = $hm[2] === 'self' ? 'resource_uri' : 'resource_uri_other_host';
                $sites[$bucket][] = [$file, $start, $end, $value];
                continue;
            }

            // First argument of #[DbQuery('name')]. Only the first positional
            // argument: the extension only looks at that one, so counting
            // named arguments like type: 'row' would report misses that are
            // not misses.
            $arg = $node->getParent();
            $list = $arg?->getParent();
            $attr = $list?->getParent();
            if ($attr instanceof Microsoft\PhpParser\Node\Attribute
                && str_contains((string) $attr->getText(), 'DbQuery')
                && $arg instanceof Microsoft\PhpParser\Node\Expression\ArgumentExpression
                && $arg->name === null
                && ($list?->children[0] ?? null) === $arg) {
                $sites['sql_query'][] = [$file, $start, $end, $value];
                continue;
            }

            if ($isRoute && str_starts_with($value, '/')) {
                $sites['route_path'][] = [$file, $start, $end, $value];
            }
        }

        // @Query("name") lives in a docblock, so there is no string-literal
        // node to take positions from; the quote positions come from the
        // regex match itself (the quote is the byte before/after the name).
        if (preg_match_all('#@Query\s*\(\s*[\'"]([a-z0-9_]+)[\'"]#i', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$val, $off]) {
                $sites['sql_query'][] = [$file, $off - 1, $off + strlen($val) + 1, $val];
            }
        }
    }

    return $sites;
}

/**
 * Expand sites into probes: two boundary probes per site (one byte before
 * the start, one byte after the end) plus one on-name F12 probe per class
 * declaration name. Class declaration names have no after probe: the
 * position right after the last character is where editors treat the cursor
 * as "on the word" (firing there is normal), and one byte further out the
 * parser returns a different node, so the extension's guard is never
 * reached. String literals are different — the closing quote is a
 * delimiter, not an identifier — so their after probe stays right after the
 * closing quote.
 *
 * @return list<array{kind: string, file: string, offset: int, value: string, edge: string, extra: ?string}>
 */
function buildProbes(array $sites): array
{
    $probes = [];
    foreach ($sites as $kind => $list) {
        foreach ($list as $site) {
            [$file, $start, $end, $value] = $site;
            $extra = $site[4] ?? null;
            $probes[] = ['kind' => $kind, 'file' => $file, 'offset' => $start - 1, 'value' => $value, 'edge' => 'before', 'extra' => $extra];
            // Class names have no after probe: the position right after the
            // last character (getEndPosition()) is where editors treat the
            // cursor as "on the word" (firing there is normal), and one byte
            // further out the parser returns a different node, not the
            // ClassDeclaration, so the extension's guard is never reached.
            // There is no position on the back side where a misfire could
            // occur — do not add one back. String literals are different:
            // the closing quote is a delimiter, not an identifier, so their
            // after probe stays right after the closing quote.
            if ($kind !== 'schema_class') {
                $probes[] = ['kind' => $kind, 'file' => $file, 'offset' => $end, 'value' => $value, 'edge' => 'after', 'extra' => $extra];
            }
            if ($kind === 'schema_class') {
                $probes[] = ['kind' => 'schema_class_f12', 'file' => $file, 'offset' => $start, 'value' => $value, 'edge' => 'on-name', 'extra' => $extra];
            }
        }
    }

    return $probes;
}

// ---- false-positive signatures (per feature, never merged) ------------

/** Whether a path is a JSON schema file (var/json_schema or var/json_validate). */
function isSchemaFile(string $path): bool
{
    return (bool) preg_match('#/var/json_(schema|validate)/#', $path);
}

/** Whether a path is a resource class file (Resource/App or Resource/Page). */
function isResourceClassFile(string $path): bool
{
    return (bool) preg_match('#/Resource/(App|Page)/.+\.php$#', $path);
}

/** Whether a path is a SQL file under the SQL directory. */
function isSqlFile(string $path): bool
{
    return (bool) preg_match('#/var/db/sql/.+\.sql$#', $path);
}

/** Whether a path is a Page resource class file (the route feature's destination). */
function isPageResourceFile(string $path): bool
{
    return (bool) preg_match('#/Resource/Page/.+\.php$#', $path);
}

/**
 * Whether a landing is a false positive for a probe kind: it landed on a
 * destination this extension could produce for that feature. A null landing
 * (no answer) is never a false positive — the built-in may legitimately
 * answer, and only the feature's own signature counts.
 */
function isFalsePositive(string $kind, ?string $actual): bool
{
    if ($actual === null) {
        return false;
    }

    return match ($kind) {
        'schema_class', 'schema_class_f12' => isSchemaFile($actual),
        'resource_uri', 'resource_uri_other_host' => isResourceClassFile($actual),
        'sql_query' => isSqlFile($actual),
        'route_path' => isPageResourceFile($actual),
    };
}

/**
 * Normalize an LSP file:// URI to a real filesystem path. The server may
 * return /tmp while the app lives under /private/tmp (macOS symlink), so
 * both sides are realpath'd before comparison.
 */
function realpathUri(?string $target): ?string
{
    if ($target === null) {
        return null;
    }
    $path = (string) preg_replace('#^file://#', '', $target);
    $path = rawurldecode($path);
    $real = realpath($path);

    return $real === false ? $path : $real;
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
$probes = buildProbes($sites);
$id = 100;
$opened = [];
$results = [];
foreach (['resource_uri', 'resource_uri_other_host', 'sql_query', 'route_path', 'schema_class', 'schema_class_f12'] as $kind) {
    $results[$kind] = ['false_positive' => [], 'ok' => 0, 'picker' => 0];
}
$noResponse = [];

foreach ($probes as $probe) {
    $kind = $probe['kind'];
    $file = $probe['file'];
    $offset = $probe['offset'];
    $text = (string) file_get_contents($file);
    $uri = 'file://' . $file;
    if (!isset($opened[$file])) {
        $send(['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
            'textDocument' => ['uri' => $uri, 'languageId' => 'php', 'version' => 1, 'text' => $text],
        ]]);
        $opened[$file] = true;
    }
    // The method follows the feature: the convention schema jump lives on
    // typeDefinition, everything else on definition. The F12 check sends
    // definition on the class name itself.
    $method = $kind === 'schema_class' ? 'textDocument/typeDefinition' : 'textDocument/definition';
    $send(['jsonrpc' => '2.0', 'id' => ++$id, 'method' => $method, 'params' => [
        'textDocument' => ['uri' => $uri],
        'position' => toPosition($text, $offset),
    ]]);
    $pickerBefore = $pickerCount;
    $res = $readUntilId($id);

    $pos = toPosition($text, $offset);
    $where = sprintf('%s:%d  %s', str_replace($app . '/', '', $file), $pos['line'] + 1, $probe['value']);

    if ($res === null) {
        fwrite(STDERR, "エラー: 言語サーバーが落ちた ({$where})\n");
        break;
    }
    if ($res === false) {
        $noResponse[] = $where . '  [15秒無応答]';
        $results[$kind]['ok']++;
        continue;
    }

    // A selection dialog means the server found several candidates and asked
    // the client to choose. The meter auto-picks the first action
    // (readUntilId) and cannot tell which candidate is right, so these are
    // counted separately — never as a false positive or as no problem.
    if ($pickerCount > $pickerBefore) {
        $results[$kind]['picker']++;
        continue;
    }

    $result = $res['result'] ?? null;
    $target = is_array($result) ? ($result['uri'] ?? ($result[0]['uri'] ?? null)) : null;
    $actual = realpathUri($target);

    if (isFalsePositive($kind, $actual)) {
        $edgeLabel = match ($probe['edge']) {
            'before' => $kind === 'schema_class' ? '名前の直前' : '文字列の直前',
            'after' => '文字列の直後',
            'on-name' => '名前の上 (F12)',
        };
        $actualLabel = $actual === null ? '(応答なし)' : str_replace($app . '/', '', $actual);
        $results[$kind]['false_positive'][] = sprintf('%s  %s → %s', $where, $edgeLabel, $actualLabel);
    } else {
        $results[$kind]['ok']++;
    }
}

$send(['jsonrpc' => '2.0', 'id' => 9999, 'method' => 'shutdown', 'params' => new stdClass()]);
$readUntilId(9999);
foreach ($pipes as $p) {
    @fclose($p);
}
proc_terminate($proc);

// ---- 報告 --------------------------------------------------------------

$siteLabels = [
    'resource_uri' => 'リソースURI(self)',
    'resource_uri_other_host' => 'リソースURI(他ホスト)',
    'sql_query' => 'クエリ名',
    'route_path' => 'ルートパス',
    'schema_class' => 'クラス宣言名',
];

// resource_uri and resource_uri_other_host share one feature, one method and
// one signature; they are reported as one row. The population above keeps the
// per-bucket breakdown.
$groups = [
    'schema_class' => ['label' => 'クラス宣言名まわり (typeDefinition)', 'kinds' => ['schema_class']],
    'resource_uri' => ['label' => 'リソースURIまわり (definition)', 'kinds' => ['resource_uri', 'resource_uri_other_host']],
    'sql_query' => ['label' => 'クエリ名まわり (definition)', 'kinds' => ['sql_query']],
    'route_path' => ['label' => 'ルートパスまわり (definition)', 'kinds' => ['route_path']],
    'schema_class_f12' => ['label' => 'F12 クラス名の上 (definition)', 'kinds' => ['schema_class_f12']],
];

echo "\n対象: {$app}\n";
echo str_repeat('=', 72), "\n";

// 母集団: the site count per kind, so a 0 is distinguishable from a broken
// collection. The probe total follows: 2 boundary probes per site plus one
// F12 probe per class name.
$siteTotal = array_sum(array_map('count', $sites));
echo "\n母集団 (地点): {$siteTotal}件\n";
foreach ($sites as $kind => $list) {
    printf("  %-24s %4d\n", $siteLabels[$kind], count($list));
}
$probeTotal = count($probes);
$f12Total = count($sites['schema_class']);
printf("\nプローブ: %d件 (境界 %d + F12 %d)\n", $probeTotal, $probeTotal - $f12Total, $f12Total);

echo "\n結果:\n";
$totals = ['false_positive' => 0, 'ok' => 0, 'picker' => 0, 'probes' => 0];
foreach ($groups as $g) {
    $fp = 0;
    $ok = 0;
    $picker = 0;
    $probeCount = 0;
    foreach ($g['kinds'] as $kind) {
        $r = $results[$kind];
        $fp += count($r['false_positive']);
        $ok += $r['ok'];
        $picker += $r['picker'];
    }
    foreach ($probes as $probe) {
        if (in_array($probe['kind'], $g['kinds'], true)) {
            $probeCount++;
        }
    }
    $totals['false_positive'] += $fp;
    $totals['ok'] += $ok;
    $totals['picker'] += $picker;
    $totals['probes'] += $probeCount;
    printf(
        "  %-38s プローブ %4d  誤爆 %3d  問題なし %4d  選択 %3d\n",
        $g['label'],
        $probeCount,
        $fp,
        $ok,
        $picker
    );
}
echo str_repeat('-', 72), "\n";
printf(
    "  %-38s プローブ %4d  誤爆 %3d  問題なし %4d  選択 %3d\n",
    '合計',
    $totals['probes'],
    $totals['false_positive'],
    $totals['ok'],
    $totals['picker']
);

foreach ($groups as $g) {
    $details = [];
    foreach ($g['kinds'] as $kind) {
        foreach ($results[$kind]['false_positive'] as $d) {
            $details[] = $d;
        }
    }
    if ($details !== []) {
        echo "\n誤爆 — ", $g['label'], "\n";
        foreach ($details as $d) {
            echo "  ", $d, "\n";
        }
    }
}

if ($noResponse !== []) {
    echo "\n応答なし ", count($noResponse), "件 (問題なしに含む):\n";
    foreach ($noResponse as $n) {
        echo "  ", $n, "\n";
    }
}

echo "\n補完 (completion) の誤爆は測っていない。候補一覧の中身の検分が必要で、別の道具になる。\n";
echo "\n注記: クラス宣言名の後ろ側は試さない。名前の直後 (getEndPosition()) はエディタが「単語の上」として扱う位置なので発火するのが正常、\n";
echo "その1つ後ろ (end + 1) はパーサーが ClassDeclaration 以外のノードを返すため当拡張のコードに到達しない。試す意味のある位置が無い。\n";
echo "\n";
