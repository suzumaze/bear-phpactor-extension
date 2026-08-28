<?php

declare(strict_types=1);

/**
 * 目視では確かめられないものを機械で検査する。
 *
 *   php tools/verify-invariants.php /path/to/app
 *
 * ── なぜ画面から数字を読ませないのか ──────────────────────────────
 *
 * 手順書に「69件」と書くと、フィクスチャが1行変わるたびに期待値が古びる。
 * そのうえ VS Code は結果が1件だと件数を出さずに直接ジャンプするので、
 * 「0件」「1件」「コマンドが動いていない」が画面上で見分けられない。
 * 検証したエージェントからその指摘を受けて用意した。
 *
 * ここで検査するのは件数ではなく**不変条件**にする。数がいくつであれ
 * 成り立っていなければならない性質だけを見るので、フィクスチャの変更で
 * 壊れない。
 *
 *   A. クラス宣言名から引いた参照と、URI文字列から引いた参照が同じ集合
 *   B. ミニアプリのリソースの参照に、別アプリ (src/) の場所が混ざらない
 *   C. 未保存の編集があっても、保存時と同じ集合が返る
 *   D. 返ってきた各参照から定義へ移動すると、起点のクラスへ戻る
 *   E. クラス宣言名の上の「定義へ移動」は、そのクラス自身を指す
 *      (VS Code の慣習を上書きしていない。かつて JSON Schema へ飛ばしていた)
 *   F. クラス宣言名の上の「型定義へ移動」が JSON Schema を指す
 *   G. 普通のPHPの型定義が壊れていない (当拡張が鎖を止めていない)
 *
 * 終了コードは、全部通れば0、1つでも破れていれば1。CIに置ける。
 */

$app = $argv[1] ?? null;
if ($app === null || !is_dir($app)) {
    fwrite(STDERR, "usage: php tools/verify-invariants.php <app-dir>\n");
    exit(1);
}
$app = (string) realpath($app);
$bin = dirname(__DIR__) . '/vendor/bin/phpactor';

// ---- LSP セッション -----------------------------------------------------

$proc = proc_open([$bin, 'language-server'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $p, $app);
if (!is_resource($proc)) {
    fwrite(STDERR, "言語サーバーを起動できませんでした\n");
    exit(1);
}
$send = function (array $m) use ($p): void {
    $b = json_encode($m, JSON_UNESCAPED_SLASHES);
    fwrite($p[0], "Content-Length: " . strlen($b) . "\r\n\r\n" . $b);
    fflush($p[0]);
};
$read = function () use ($p) {
    $h = '';
    while (!str_contains($h, "\r\n\r\n")) {
        $c = fread($p[1], 1);
        if ($c === '' || $c === false) {
            return null;
        }
        $h .= $c;
    }
    preg_match('/Content-Length: (\d+)/i', $h, $m);
    $l = (int) $m[1];
    $b = '';
    while (strlen($b) < $l) {
        $c = fread($p[1], $l - strlen($b));
        if ($c === '' || $c === false) {
            return null;
        }
        $b .= $c;
    }

    return json_decode($b, true);
};
// サーバーからの要求には必ず返事をする。返さないと相手が待ち続ける
// (window/workDoneProgress/create を無視して900秒待った実例がある)。
$until = function (int $id) use ($read, $send) {
    for ($i = 0; $i < 20000; $i++) {
        $m = $read();
        if (!$m) {
            return null;
        }
        if (isset($m['id'], $m['method'])) {
            $reply = null;
            if ($m['method'] === 'window/showMessageRequest') {
                $actions = $m['params']['actions'] ?? [];
                $reply = $actions[count($actions) - 1] ?? null;   // 「最後まで続けろ」
            }
            $send(['jsonrpc' => '2.0', 'id' => $m['id'], 'result' => $reply]);
            continue;
        }
        if (($m['id'] ?? null) === $id) {
            return $m;
        }
    }

    return null;
};

$send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
    'processId' => getmypid(), 'rootUri' => 'file://' . $app, 'capabilities' => new stdClass(),
]]);
$until(1);
$send(['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => new stdClass()]);
for ($i = 0; $i < 5000; $i++) {
    $m = $read();
    if (!$m) {
        break;
    }
    if (($m['method'] ?? '') === 'window/showMessage'
        && str_contains($m['params']['message'] ?? '', 'Done indexing')) {
        break;
    }
}

$id = 100;
$open = function (string $file, ?string $text = null) use ($send): void {
    $send(['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => ['textDocument' => [
        'uri' => 'file://' . $file, 'languageId' => 'php', 'version' => 1,
        'text' => $text ?? (string) file_get_contents($file),
    ]]]);
};
$pos = function (string $t, int $o): array {
    $b = substr($t, 0, $o);

    return ['line' => substr_count($b, "\n"), 'character' => $o - (int) strrpos("\n" . $b, "\n")];
};
/** @return list<string> "path:line:char" にそろえた参照の集合 (ソート済み) */
$references = function (string $file, string $text, int $offset) use (&$id, $send, $until, $pos, $app): array {
    $send(['jsonrpc' => '2.0', 'id' => ++$id, 'method' => 'textDocument/references', 'params' => [
        'textDocument' => ['uri' => 'file://' . $file],
        'position' => $pos($text, $offset),
        'context' => ['includeDeclaration' => false],   // 宣言の枠は別機能なので外す
    ]]);
    $r = $until($id);
    $out = [];
    foreach (($r['result'] ?? []) as $loc) {
        $out[] = sprintf('%s:%d:%d',
            str_replace($app . '/', '', (string) preg_replace('#^file://#', '', $loc['uri'])),
            $loc['range']['start']['line'] + 1, $loc['range']['start']['character']);
    }
    sort($out);

    return $out;
};
$typeDefinition = function (string $file, string $text, int $offset) use (&$id, $send, $until, $pos, $app): ?string {
    $send(['jsonrpc' => '2.0', 'id' => ++$id, 'method' => 'textDocument/typeDefinition', 'params' => [
        'textDocument' => ['uri' => 'file://' . $file], 'position' => $pos($text, $offset),
    ]]);
    $r = $until($id);
    $res = $r['result'] ?? null;
    $uri = is_array($res) ? ($res['uri'] ?? ($res[0]['uri'] ?? null)) : null;

    return $uri === null ? null : str_replace($app . '/', '', (string) preg_replace('#^file://#', '', $uri));
};
$definition = function (string $file, string $text, int $offset) use (&$id, $send, $until, $pos, $app): ?string {
    $send(['jsonrpc' => '2.0', 'id' => ++$id, 'method' => 'textDocument/definition', 'params' => [
        'textDocument' => ['uri' => 'file://' . $file], 'position' => $pos($text, $offset),
    ]]);
    $r = $until($id);
    $res = $r['result'] ?? null;
    $uri = is_array($res) ? ($res['uri'] ?? ($res[0]['uri'] ?? null)) : null;

    return $uri === null ? null : str_replace($app . '/', '', (string) preg_replace('#^file://#', '', $uri));
};

// ---- 検査 ---------------------------------------------------------------

$results = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$article  = $app . '/src/Resource/App/Article.php';
$articles = $app . '/src/Resource/App/Articles.php';
$mini     = $app . '/tests/Fake/Defer/Resource/App/Article.php';
foreach ([$article, $articles, $mini] as $f) {
    if (!is_file($f)) {
        fwrite(STDERR, "検査に使うファイルがありません: {$f}\n");
        exit(1);
    }
}

$articleText  = (string) file_get_contents($article);
$articlesText = (string) file_get_contents($articles);
$miniText     = (string) file_get_contents($mini);
$open($article);
$open($articles);
$open($mini);

// A. クラス宣言名から引いた集合と、URI文字列から引いた集合が一致する
$fromClass = $references($article, $articleText,
    strpos($articleText, 'class Article extends') + strlen('class '));
$fromUri = $references($articles, $articlesText,
    strpos($articlesText, 'app://self/article{?id}') + 2);
$check('A. クラス名から / URI文字列から が同じ集合',
    $fromClass === $fromUri && $fromClass !== [],
    sprintf('クラス名 %d件 / URI文字列 %d件%s', count($fromClass), count($fromUri),
        $fromClass === $fromUri ? '' : ' — 差: ' . implode(', ',
            array_slice(array_merge(array_diff($fromClass, $fromUri), array_diff($fromUri, $fromClass)), 0, 4))));

// B. ミニアプリの参照に別アプリ (src/) が混ざらない
$fromMini = $references($mini, $miniText,
    strpos($miniText, 'class Article extends') + strlen('class '));
$leaked = array_values(array_filter($fromMini, static fn (string $s): bool => str_starts_with($s, 'src/')));
$check('B. ミニアプリの参照に src/ が混ざらない', $leaked === [],
    $leaked === [] ? sprintf('%d件、うち src/ は0件', count($fromMini)) : '混ざった: ' . implode(', ', $leaked));

// C. 未保存の編集があっても同じ集合が返る
$edited = (string) preg_replace('/^<\?php/', "<?php\n\n", $articleText, 1);
$send(['jsonrpc' => '2.0', 'method' => 'textDocument/didChange', 'params' => [
    'textDocument' => ['uri' => 'file://' . $article, 'version' => 2],
    'contentChanges' => [['text' => $edited]],
]]);
$fromEdited = $references($article, $edited,
    strpos($edited, 'class Article extends') + strlen('class '));
$check('C. 未保存の編集があっても同じ集合', $fromEdited === $fromClass,
    sprintf('編集前 %d件 / 編集後 %d件', count($fromClass), count($fromEdited)));
// 元に戻す
$send(['jsonrpc' => '2.0', 'method' => 'textDocument/didChange', 'params' => [
    'textDocument' => ['uri' => 'file://' . $article, 'version' => 3],
    'contentChanges' => [['text' => $articleText]],
]]);

// D. 各参照から定義へ移動すると起点へ戻る
$ng = [];
foreach ($fromClass as $site) {
    [$rel, $line, $char] = explode(':', $site);
    $path = $app . '/' . $rel;
    $text = (string) file_get_contents($path);
    $open($path);
    $lines = explode("\n", $text);
    $offset = strlen(implode("\n", array_slice($lines, 0, (int) $line - 1))) + ((int) $line > 1 ? 1 : 0) + (int) $char + 1;
    $back = $definition($path, $text, $offset);
    if ($back !== 'src/Resource/App/Article.php') {
        $ng[] = $site . ' -> ' . ($back ?? 'どこへも飛ばない');
    }
}
$check('D. 参照から定義へ移動すると起点へ戻る', $ng === [],
    $ng === [] ? sprintf('%d件すべて往復', count($fromClass))
               : sprintf('%d件中 %d件が戻らない: %s', count($fromClass), count($ng), implode(' / ', array_slice($ng, 0, 3))));

// E. クラス宣言名の上の「定義へ移動」はクラス自身。VS Code の慣習を上書きしない
$offClass = strpos($articleText, 'class Article extends') + strlen('class ');
$def = $definition($article, $articleText, $offClass);
$check('E. クラス宣言名のF12はクラス自身', $def === 'src/Resource/App/Article.php',
    '飛び先: ' . ($def ?? 'null'));

// F. 同じ位置の「型定義へ移動」が JSON Schema を指す
$type = $typeDefinition($article, $articleText, $offClass);
$check('F. クラス宣言名の型定義はJSON Schema', $type === 'var/json_schema/article.json',
    '飛び先: ' . ($type ?? 'null'));

// G. 普通のPHPの型定義が壊れていない。当拡張が該当しないときに例外を投げると
//    ChainTypeLocator が止まり、全PHPコードの「型定義へ移動」が死ぬ
$offVar = strpos($articleText, '$this->article->item(');
$plain = $offVar === false ? null : $typeDefinition($article, $articleText, $offVar + strlen('$this->'));
$check('G. 普通のPHPの型定義が壊れていない',
    $plain !== null && !str_starts_with($plain, 'var/json_schema/'),
    '飛び先: ' . ($plain ?? 'null（鎖が止まっている疑い）'));

$send(['jsonrpc' => '2.0', 'id' => 9999, 'method' => 'shutdown', 'params' => new stdClass()]);
$until(9999);
foreach ($p as $x) {
    @fclose($x);
}
proc_terminate($proc);

// ---- 報告 ---------------------------------------------------------------

echo "\n対象: {$app}\n", str_repeat('=', 72), "\n";
$failed = 0;
foreach ($results as $r) {
    printf("  %s  %-40s %s\n", $r['ok'] ? '通過' : '★不合格', $r['name'], $r['detail']);
    $failed += $r['ok'] ? 0 : 1;
}
echo str_repeat('-', 72), "\n";
echo $failed === 0
    ? "  すべて通過。件数そのものは検査しない（フィクスチャが変われば変わるため）\n\n"
    : sprintf("  ★ %d件が破れている\n\n", $failed);

exit($failed === 0 ? 0 : 1);
