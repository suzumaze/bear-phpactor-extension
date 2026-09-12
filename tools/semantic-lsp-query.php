#!/usr/bin/env php
<?php

/**
 * Minimal headless client for the read-only BEAR semantic LSP requests.
 *
 * Usage:
 *   php tools/semantic-lsp-query.php WORKSPACE METHOD '{"uri":"app://self/user"}'
 *
 * Set PHPACTOR_BIN when Phpactor is not installed in WORKSPACE/vendor/bin or
 * this repository's vendor/bin directory.
 */

declare(strict_types=1);

const METHODS = [
    'bear/project/info',
    'bear/resource/resolve',
    'bear/resource/list',
    'bear/resource/describe',
    'bear/resource/incomingRelations',
    'bear/route/resolve',
    'bear/sql/resolve',
    'bear/template/resolve',
    'bear/template/forResource',
    'bear/alps/resolveDescriptor',
    'bear/alps/describeDescriptor',
    'bear/schema/resolveNamed',
    'bear/schema/forResource',
    'bear/schema/describeNamed',
    'bear/schema/describeForResource',
];

$arguments = $_SERVER['argv'] ?? [];
$script = $arguments[0] ?? 'tools/semantic-lsp-query.php';
$workspaceArgument = $arguments[1] ?? null;
$method = $arguments[2] ?? null;
$jsonParams = $arguments[3] ?? null;
if (
    count($arguments) !== 4
    || !is_string($script)
    || !is_string($workspaceArgument)
    || !is_string($method)
    || !is_string($jsonParams)
    || !in_array($method, METHODS, true)
) {
    fwrite(STDERR, sprintf(
        "Usage: %s WORKSPACE METHOD JSON_PARAMS\nMethods:\n  %s\n",
        is_string($script) ? $script : 'tools/semantic-lsp-query.php',
        implode("\n  ", METHODS),
    ));
    exit(2);
}

$workspace = realpath($workspaceArgument);
if ($workspace === false || !is_dir($workspace)) {
    fwrite(STDERR, "Workspace does not exist\n");
    exit(2);
}

try {
    $params = json_decode($jsonParams, true, 64, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, 'Invalid JSON params: ' . $exception->getMessage() . "\n");
    exit(2);
}
if (!is_array($params) || array_is_list($params)) {
    fwrite(STDERR, "JSON_PARAMS must be an object\n");
    exit(2);
}

$phpactor = getenv('PHPACTOR_BIN');
if (!is_string($phpactor) || $phpactor === '') {
    $candidates = [
        $workspace . '/vendor/bin/phpactor',
        dirname(__DIR__) . '/vendor/bin/phpactor',
    ];
    $phpactor = 'phpactor';
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $phpactor = $candidate;
            break;
        }
    }
}

$process = proc_open(
    [$phpactor, 'language-server', '--working-dir=' . $workspace],
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    $workspace,
);
if (!is_resource($process)) {
    fwrite(STDERR, "Could not start Phpactor\n");
    exit(1);
}

stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
$buffer = '';
$stderr = '';
$nextId = 0;

/** @param array<string,mixed> $message */
$send = static function (array $message) use ($pipes): void {
    $body = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $frame = sprintf("Content-Length: %d\r\n\r\n%s", strlen($body), $body);
    if (fwrite($pipes[0], $frame) !== strlen($frame)) {
        throw new RuntimeException('Could not write a complete LSP frame');
    }
    fflush($pipes[0]);
};

/** @return array<string,mixed> */
$read = static function (float $timeout = 20.0) use ($pipes, &$buffer, &$stderr): array {
    $deadline = microtime(true) + $timeout;
    while (true) {
        $separator = strpos($buffer, "\r\n\r\n");
        if ($separator !== false) {
            $header = substr($buffer, 0, $separator);
            if (preg_match('/(?:^|\r\n)Content-Length:\s*(\d+)/i', $header, $matches) !== 1) {
                throw new RuntimeException('Invalid LSP header');
            }
            $length = (int) $matches[1];
            if (strlen($buffer) >= $separator + 4 + $length) {
                $body = substr($buffer, $separator + 4, $length);
                $buffer = substr($buffer, $separator + 4 + $length);
                $message = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($message)) {
                    throw new RuntimeException('LSP response is not an object');
                }

                return $message;
            }
        }

        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException("Timed out waiting for Phpactor\n" . trim($stderr));
        }
        $streams = [$pipes[1], $pipes[2]];
        $write = null;
        $except = null;
        $seconds = (int) $remaining;
        $microseconds = (int) (($remaining - $seconds) * 1_000_000);
        if (stream_select($streams, $write, $except, $seconds, $microseconds) < 1) {
            continue;
        }
        foreach ($streams as $stream) {
            $chunk = fread($stream, 8192);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            if ($stream === $pipes[1]) {
                $buffer .= $chunk;
            } else {
                $stderr .= $chunk;
            }
        }
    }
};

/**
 * @param array<string,mixed> $requestParams
 * @return array<string,mixed>
 */
$request = static function (string $method, array $requestParams) use ($send, $read, &$nextId): array {
    $id = ++$nextId;
    $send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $requestParams]);
    while (true) {
        $message = $read();
        if (isset($message['id'], $message['method'])) {
            $result = null;
            if ($message['method'] === 'workspace/configuration') {
                $items = $message['params']['items'] ?? [];
                $result = is_array($items) ? array_fill(0, count($items), null) : [];
            }
            $send(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result]);
            continue;
        }
        if (($message['id'] ?? null) === $id) {
            return $message;
        }
    }
};

try {
    $initialize = $request('initialize', [
        'processId' => getmypid(),
        'rootUri' => 'file://' . str_replace('%2F', '/', rawurlencode($workspace)),
        'capabilities' => (object) [],
    ]);
    if (isset($initialize['error'])) {
        throw new RuntimeException('Initialize failed: ' . json_encode($initialize['error']));
    }
    $send(['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => (object) []]);

    $response = $request($method, $params);
    if (isset($response['error'])) {
        throw new RuntimeException('Request failed: ' . json_encode($response['error']));
    }
    fwrite(STDOUT, json_encode(
        $response['result'] ?? null,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    ) . "\n");

    $request('shutdown', []);
    $send(['jsonrpc' => '2.0', 'method' => 'exit', 'params' => (object) []]);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n" . trim($stderr) . "\n");
    exit(1);
} finally {
    if (is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    foreach ([1, 2] as $descriptor) {
        if (is_resource($pipes[$descriptor])) {
            fclose($pipes[$descriptor]);
        }
    }
    proc_terminate($process);
    proc_close($process);
}
