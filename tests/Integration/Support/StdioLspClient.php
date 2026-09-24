<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Integration\Support;

use RuntimeException;

/**
 * Minimal JSON-RPC/LSP stdio client for child-process integration tests.
 */
final class StdioLspClient
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private string $stdoutBuffer = '';
    private string $stderr = '';
    private int $nextId = 0;
    private bool $closed = false;

    /**
     * @param resource             $process
     * @param array<int, resource> $pipes
     */
    private function __construct($process, array $pipes)
    {
        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    /**
     * @param list<string>         $command
     * @param array<string,string> $environment
     */
    public static function start(array $command, string $workingDirectory, array $environment): self
    {
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory,
            $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the Phpactor language server.');
        }

        /** @var array<int, resource> $pipes */
        return new self($process, $pipes);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function request(string $method, array $params, float $timeoutSeconds = 10.0): array
    {
        $id = ++$this->nextId;
        $this->send([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]);

        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $message = $this->readMessage($deadline);
            if (isset($message['method'], $message['id'])) {
                $this->replyToServerRequest($message);
                continue;
            }

            if (($message['id'] ?? null) === $id) {
                return $message;
            }
        }
    }

    /**
     * @param array<string,mixed> $params
     */
    public function notify(string $method, array $params = []): void
    {
        $this->send([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params === [] ? (object) [] : $params,
        ]);
    }

    /**
     * @param (callable(array<string,mixed>):bool)|null $predicate
     * @return array<string,mixed>
     */
    public function waitForNotification(
        string $method,
        ?callable $predicate = null,
        float $timeoutSeconds = 10.0,
    ): array {
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $message = $this->readMessage($deadline);
            if (isset($message['method'], $message['id'])) {
                $this->replyToServerRequest($message);
                continue;
            }
            if (($message['method'] ?? null) !== $method) {
                continue;
            }
            if ($predicate !== null && !$predicate($message)) {
                continue;
            }

            return $message;
        }
    }

    public function stderr(): string
    {
        $this->drainAvailableStderr();

        return $this->stderr;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        if (is_resource($this->pipes[0])) {
            fclose($this->pipes[0]);
        }

        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                break;
            }
            $this->drainAvailableStderr();
            usleep(10_000);
        }

        $status = proc_get_status($this->process);
        if ($status['running']) {
            proc_terminate($this->process);
        }

        foreach ([1, 2] as $descriptor) {
            if (is_resource($this->pipes[$descriptor])) {
                fclose($this->pipes[$descriptor]);
            }
        }
        proc_close($this->process);
    }

    /**
     * @param array<string,mixed> $message
     */
    private function send(array $message): void
    {
        if ($this->closed || !is_resource($this->pipes[0])) {
            throw new RuntimeException('The LSP client is closed.');
        }

        $body = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $frame = sprintf("Content-Length: %d\r\n\r\n%s", strlen($body), $body);
        $written = fwrite($this->pipes[0], $frame);
        if ($written === false || $written !== strlen($frame)) {
            throw new RuntimeException('Could not write a complete LSP frame.');
        }
        fflush($this->pipes[0]);
    }

    /**
     * @return array<string,mixed>
     */
    private function readMessage(float $deadline): array
    {
        while (($separator = strpos($this->stdoutBuffer, "\r\n\r\n")) === false) {
            $this->readOutputChunk($deadline);
        }

        $header = substr($this->stdoutBuffer, 0, $separator);
        $this->stdoutBuffer = substr($this->stdoutBuffer, $separator + 4);
        if (preg_match('/(?:^|\r\n)Content-Length:\s*(\d+)/i', $header, $matches) !== 1) {
            throw new RuntimeException(sprintf('Invalid LSP header: %s', $header));
        }
        $length = (int) $matches[1];

        while (strlen($this->stdoutBuffer) < $length) {
            $this->readOutputChunk($deadline);
        }

        $body = substr($this->stdoutBuffer, 0, $length);
        $this->stdoutBuffer = substr($this->stdoutBuffer, $length);
        $message = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($message)) {
            throw new RuntimeException('The LSP server returned a non-object message.');
        }

        return $message;
    }

    private function readOutputChunk(float $deadline): void
    {
        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('Timed out waiting for LSP output.' . $this->stderrSuffix());
            }

            $read = [$this->pipes[1], $this->pipes[2]];
            $write = null;
            $except = null;
            $seconds = (int) $remaining;
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);
            $ready = stream_select($read, $write, $except, $seconds, $microseconds);
            if ($ready === false) {
                throw new RuntimeException('Could not wait for LSP output.' . $this->stderrSuffix());
            }
            if ($ready === 0) {
                throw new RuntimeException('Timed out waiting for LSP output.' . $this->stderrSuffix());
            }

            $receivedStdout = false;
            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($stream === $this->pipes[1]) {
                    $this->stdoutBuffer .= $chunk;
                    $receivedStdout = true;
                    continue;
                }
                $this->stderr .= $chunk;
            }

            if ($receivedStdout) {
                return;
            }

            $status = proc_get_status($this->process);
            if (!$status['running'] && feof($this->pipes[1])) {
                throw new RuntimeException('The LSP server exited before responding.' . $this->stderrSuffix());
            }
        }
    }

    /**
     * @param array<string,mixed> $message
     */
    private function replyToServerRequest(array $message): void
    {
        $result = null;
        if ($message['method'] === 'workspace/configuration') {
            $items = $message['params']['items'] ?? [];
            $result = is_array($items) ? array_fill(0, count($items), null) : [];
        }

        $this->send([
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => $result,
        ]);
    }

    private function drainAvailableStderr(): void
    {
        if (!is_resource($this->pipes[2])) {
            return;
        }

        while (($chunk = fread($this->pipes[2], 8192)) !== false && $chunk !== '') {
            $this->stderr .= $chunk;
        }
    }

    private function stderrSuffix(): string
    {
        $stderr = trim($this->stderr());

        return $stderr === '' ? '' : sprintf("\nPhpactor stderr:\n%s", $stderr);
    }
}
