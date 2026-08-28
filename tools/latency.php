<?php

declare(strict_types=1);

/**
 * 定義ジャンプ1回にどれだけ掛かるかの測定。
 *
 *   php tools/latency.php /path/to/bear-app
 *
 * 到達率 (tools/coverage.php) とは道具を分けてある。あちらは種類ごとに
 * ファイルの開かれ方が違うので、速度を公平に比べられない。
 *
 * ここでは同じ位置に同じ要求を繰り返し、2つを分けて出す:
 *
 *   冷 … ファイルを開いた直後の1回目。サーバーがそのドキュメントを取り込むぶんを含む
 *   温 … 同じファイルでの4回目。これが実際の1回あたりの費用
 */

$app = $argv[1] ?? null;
if ($app === null || !is_dir($app)) {
    fwrite(STDERR, "usage: php tools/latency.php <bear-sunday-app-dir>\n");
    exit(1);
}
$app = (string) realpath($app);
$bin = dirname(__DIR__) . '/vendor/bin/phpactor';

$proc = proc_open([$bin, 'language-server'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $app);
if (!is_resource($proc)) {
    fwrite(STDERR, "could not start phpactor\n");
    exit(1);
}

$send = function (array $m) use ($pipes): void {
    $b = json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    fwrite($pipes[0], 'Content-Length: ' . strlen($b) . "\r\n\r\n" . $b);
    fflush($pipes[0]);
};

$read = function () use ($pipes): ?array {
    $h = '';
    while (!str_contains($h, "\r\n\r\n")) {
        $c = fread($pipes[1], 1);
        if ($c === '' || $c === false) {
            return null;
        }
        $h .= $c;
    }
    preg_match('/Content-Length: (\d+)/i', $h, $m);
    $len = (int) $m[1];
    $b = '';
    while (strlen($b) < $len) {
        $c = fread($pipes[1], $len - strlen($b));
        if ($c === '' || $c === false) {
            return null;
        }
        $b .= $c;
    }

    return json_decode($b, true);
};

/** 途中で来る「候補が複数あります」の要求には返事をする。しないとサーバーが止まる。 */
$until = function (int $id) use ($read, $send): ?array {
    for ($i = 0; $i < 600; $i++) {
        $m = $read();
        if ($m === null) {
            return null;
        }
        if (isset($m['id'], $m['method'])) {
            $send(['jsonrpc' => '2.0', 'id' => $m['id'], 'result' => $m['params']['actions'][0] ?? null]);
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

fwrite(STDERR, "indexing...\n");
for ($i = 0; $i < 600; $i++) {
    $m = $read();
    if ($m === null) {
        break;
    }
    if (($m['method'] ?? '') === 'window/showMessage'
        && str_contains($m['params']['message'] ?? '', 'Done indexing')) {
        break;
    }
}

/** @return array{0:int,1:int}|null */
function positionOf(string $text, int $offset): array
{
    $before = substr($text, 0, $offset);

    return [substr_count($before, "\n"), $offset - (int) strrpos("\n" . $before, "\n")];
}

/** 測る対象を集める。種類ごとに最大20件。 */
$targets = ['リソースURI' => [], 'クラス宣言名' => []];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $path = $f->getPathname();
    if (str_contains($path, '/vendor/') || !str_ends_with($path, '.php')) {
        continue;
    }
    $text = (string) file_get_contents($path);
    if (count($targets['リソースURI']) < 20
        && preg_match('#[\'"](?:app|page)://self/#', $text, $m, PREG_OFFSET_CAPTURE)) {
        $targets['リソースURI'][] = [$path, $text, $m[0][1] + 2];
    }
    if (count($targets['クラス宣言名']) < 20
        && preg_match('#/Resource/(App|Page)/#', str_replace('\\', '/', $path))
        && preg_match('#\bclass\s+([A-Za-z_]\w*)#', $text, $m, PREG_OFFSET_CAPTURE)) {
        $targets['クラス宣言名'][] = [$path, $text, $m[1][1] + 1];
    }
}

$id = 100;
$median = static function (array $a): float {
    sort($a);

    return $a === [] ? 0.0 : $a[intdiv(count($a), 2)];
};

printf("\n対象: %s\n", $app);
echo str_repeat('=', 60), "\n";

foreach ($targets as $label => $list) {
    $cold = [];
    $warm = [];
    foreach ($list as [$path, $text, $offset]) {
        [$line, $char] = positionOf($text, $offset);
        $uri = 'file://' . $path;
        $send(['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => [
            'textDocument' => ['uri' => $uri, 'languageId' => 'php', 'version' => 1, 'text' => $text],
        ]]);
        for ($k = 0; $k < 4; $k++) {
            $t0 = microtime(true);
            $send(['jsonrpc' => '2.0', 'id' => ++$id, 'method' => 'textDocument/definition', 'params' => [
                'textDocument' => ['uri' => $uri], 'position' => ['line' => $line, 'character' => $char],
            ]]);
            $until($id);
            $ms = (microtime(true) - $t0) * 1000;
            if ($k === 0) {
                $cold[] = $ms;
            } elseif ($k === 3) {
                $warm[] = $ms;
            }
        }
    }
    if ($list === []) {
        printf("%-16s  (対象なし)\n", $label);
        continue;
    }
    printf("%-16s  %2d箇所   冷 %6.1f ms   温 %6.1f ms\n", $label, count($list), $median($cold), $median($warm));
}

echo str_repeat('-', 60), "\n";
echo "  冷 = ファイルを開いた直後の1回目 / 温 = 同じファイルの4回目\n";
echo "  冷と温の差はサーバーがドキュメントを取り込む費用で、この拡張の処理ではない\n\n";

$send(['jsonrpc' => '2.0', 'id' => 9999, 'method' => 'shutdown', 'params' => new stdClass()]);
$until(9999);
foreach ($pipes as $p) {
    @fclose($p);
}
proc_terminate($proc);
