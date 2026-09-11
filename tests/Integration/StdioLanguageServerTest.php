<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Integration;

use Suzumaze\BearPhpactor\Tests\Integration\Support\StdioLspClient;
use Phpactor\TextDocument\TextDocumentUri;
use PHPUnit\Framework\TestCase;

final class StdioLanguageServerTest extends TestCase
{
    private string $runtimeDirectory;

    protected function setUp(): void
    {
        $this->runtimeDirectory = sys_get_temp_dir() . '/bear-lsp-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->runtimeDirectory, 0777, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->runtimeDirectory);
    }

    public function testRealPhpactorStdioServerLoadsExtensionAndResolvesResourceDefinition(): void
    {
        $fixture = self::fixtureDir();
        $clientFile = $fixture . '/src/Client.php';
        $source = (string) file_get_contents($clientFile);
        $position = $this->positionOf('app://self/user', $source);
        $client = StdioLspClient::start(
            $this->command($fixture),
            $fixture,
            $this->environment(),
        );

        try {
            $initialize = $client->request('initialize', [
                'processId' => getmypid(),
                'rootUri' => $this->fileUri($fixture),
                'capabilities' => (object) [],
            ], 20.0);
            self::assertArrayNotHasKey('error', $initialize, $client->stderr());
            self::assertTrue($initialize['result']['capabilities']['definitionProvider'] ?? false);

            $client->notify('initialized');
            $client->notify('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => $this->fileUri($clientFile),
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => $source,
                ],
            ]);

            $definition = $client->request('textDocument/definition', [
                'textDocument' => ['uri' => $this->fileUri($clientFile)],
                'position' => $position,
            ], 20.0);

            self::assertArrayNotHasKey('error', $definition, $client->stderr());
            self::assertSame(
                $this->fileUri($fixture . '/src/Resource/App/User.php'),
                $definition['result']['uri'] ?? null,
                'Initialize can succeed without the BEAR extension; this Definition assertion proves it was loaded.',
            );
            self::assertSame(8, $definition['result']['range']['start']['line'] ?? null);
            self::assertSame(12, $definition['result']['range']['start']['character'] ?? null);

            $references = $client->request('textDocument/references', [
                'textDocument' => ['uri' => $this->fileUri($clientFile)],
                'position' => $position,
                'context' => ['includeDeclaration' => false],
            ], 20.0);
            self::assertArrayNotHasKey(
                'error',
                $references,
                json_encode($references, JSON_UNESCAPED_SLASHES) . "\n" . $client->stderr(),
            );
            self::assertIsArray($references['result'] ?? null);
            $referenceUris = array_column($references['result'], 'uri');
            self::assertContains($this->fileUri($clientFile), $referenceUris);
            self::assertContains(
                $this->fileUri($fixture . '/src/Resource/App/Articles.php'),
                $referenceUris,
            );

            $shutdown = $client->request('shutdown', [], 10.0);
            self::assertArrayNotHasKey('error', $shutdown, $client->stderr());
            self::assertArrayHasKey('result', $shutdown);
            self::assertNull($shutdown['result']);
            $client->notify('exit');
        } finally {
            $client->close();
        }
    }

    /** @return list<string> */
    private function command(string $fixture): array
    {
        $indexDirectory = $this->runtimeDirectory . '/index';
        self::assertTrue(mkdir($indexDirectory, 0777, true));
        $config = json_decode(
            (string) file_get_contents(self::repositoryRoot() . '/.phpactor.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($config);
        $config['indexer.enabled_watchers'] = [];
        $config['indexer.index_path'] = $indexDirectory;
        $config['language_server.diagnostics_on_open'] = false;
        $config['language_server.diagnostics_on_update'] = false;
        $config['language_server.diagnostics_on_save'] = false;

        return [
            self::repositoryRoot() . '/vendor/bin/phpactor',
            'language-server',
            '--working-dir=' . $fixture,
            '--config-extra=' . json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
    }

    /** @return array<string,string> */
    private function environment(): array
    {
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['XDG_CACHE_HOME'] = $this->runtimeDirectory . '/cache';
        $environment['XDG_CONFIG_HOME'] = $this->runtimeDirectory . '/config';

        return $environment;
    }

    /**
     * @return array{line: int, character: int}
     */
    private function positionOf(string $needle, string $source): array
    {
        $offset = strpos($source, $needle);
        self::assertNotFalse($offset);
        $before = substr($source, 0, $offset);
        $lastNewline = strrpos($before, "\n");

        return [
            'line' => substr_count($before, "\n"),
            'character' => $lastNewline === false ? $offset : $offset - $lastNewline - 1,
        ];
    }

    private function fileUri(string $path): string
    {
        return (string) TextDocumentUri::fromString($path);
    }

    private static function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function fixtureDir(): string
    {
        return dirname(__DIR__) . '/Fixture/Resource';
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
