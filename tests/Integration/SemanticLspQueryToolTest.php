<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SemanticLspQueryToolTest extends TestCase
{
    public function testAcceptsAnEmptyJsonObjectForOptionalParameters(): void
    {
        $root = dirname(__DIR__, 2);
        $phpactor = realpath(__DIR__ . '/../Fixture/SemanticCli/fake-phpactor');
        self::assertNotFalse($phpactor);

        $environment = getenv();
        $environment['PHPACTOR_BIN'] = $phpactor;
        $process = proc_open(
            [
                PHP_BINARY,
                $root . '/tools/semantic-lsp-query.php',
                $root . '/tests/Fixture/Resource',
                'bear/project/info',
                '{}',
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
            $environment,
        );
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $stderr);
        self::assertSame('', $stderr);
        $result = json_decode($stdout, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $result['status'] ?? null);
        self::assertSame('fixture', $result['data']['workspaceName'] ?? null);
    }
}
