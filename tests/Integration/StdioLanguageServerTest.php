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

            $semantic = $client->request('bear/resource/resolve', [
                'uri' => 'app://self/user',
                'contextPath' => 'src/Client.php',
            ], 20.0);
            self::assertArrayNotHasKey('error', $semantic, $client->stderr());
            self::assertSame([
                'status' => 'ok',
                'data' => [
                    'uri' => 'app://self/user',
                    'fqn' => 'Acme\\Blog\\Resource\\App\\User',
                    'path' => 'src/Resource/App/User.php',
                ],
                'candidates' => [],
            ], $semantic['result'] ?? null);

            $inventory = $client->request('bear/resource/list', [
                'scheme' => 'app',
                'prefix' => 'user',
                'limit' => 1,
            ], 20.0);
            self::assertArrayNotHasKey('error', $inventory, $client->stderr());
            self::assertSame('ok', $inventory['result']['status'] ?? null);
            self::assertSame(1, $inventory['result']['data']['total'] ?? null);
            self::assertFalse($inventory['result']['data']['truncated'] ?? true);
            self::assertSame(
                'src/Resource/App/User.php',
                $inventory['result']['data']['resources'][0]['path'] ?? null,
            );

            $description = $client->request('bear/resource/describe', [
                'uri' => 'app://self/user',
                'contextPath' => 'src/Client.php',
            ], 20.0);
            self::assertArrayNotHasKey('error', $description, $client->stderr());
            self::assertSame('ok', $description['result']['status'] ?? null);
            self::assertSame(
                'Acme\Blog\Resource\App\User',
                $description['result']['data']['resource']['fqn'] ?? null,
            );
            self::assertSame('onGet', $description['result']['data']['methods'][0]['name'] ?? null);
            self::assertSame([], $description['result']['data']['relationsOut'] ?? null);

            $invalidSemantic = $client->request('bear/resource/resolve', [
                'uri' => 'app://self/user',
                'contextPath' => '../outside.php',
            ], 20.0);
            self::assertArrayNotHasKey('error', $invalidSemantic, $client->stderr());
            self::assertSame('invalid_input', $invalidSemantic['result']['status'] ?? null);

            $client->notify('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => $this->fileUri($clientFile),
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => $source,
                ],
            ]);

            $completionNeedle = "uri('app://self/u";
            $completionOffset = strpos($source, $completionNeedle);
            self::assertNotFalse($completionOffset);
            $completion = $client->request('textDocument/completion', [
                'textDocument' => ['uri' => $this->fileUri($clientFile)],
                'position' => $this->positionAtOffset(
                    $completionOffset + strlen($completionNeedle),
                    $source,
                ),
            ], 20.0);
            self::assertArrayNotHasKey('error', $completion, $client->stderr());
            self::assertContains(
                'app://self/user',
                array_column($completion['result']['items'] ?? [], 'label'),
            );

            $documentLinks = $client->request('textDocument/documentLink', [
                'textDocument' => ['uri' => $this->fileUri($clientFile)],
            ], 20.0);
            self::assertArrayNotHasKey('error', $documentLinks, $client->stderr());
            self::assertContains(
                $this->fileUri($fixture . '/src/Resource/App/User.php'),
                array_column($documentLinks['result'] ?? [], 'target'),
            );

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

    public function testRealPhpactorStdioServerResolvesSchemaTypeDefinition(): void
    {
        $fixture = dirname(__DIR__) . '/Fixture/JsonSchema/basic';
        $resourceFile = $fixture . '/src/Resource/App/BodyTypeDemo.php';
        $sourceWithCaret = (string) file_get_contents($resourceFile);
        $offset = strpos($sourceWithCaret, '<caret>');
        self::assertNotFalse($offset);
        $source = str_replace('<caret>', '', $sourceWithCaret);
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
            self::assertTrue($initialize['result']['capabilities']['typeDefinitionProvider'] ?? false);
            $client->notify('initialized');
            $client->notify('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => $this->fileUri($resourceFile),
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => $source,
                ],
            ]);

            $typeDefinition = $client->request('textDocument/typeDefinition', [
                'textDocument' => ['uri' => $this->fileUri($resourceFile)],
                'position' => $this->positionAtOffset($offset, $source),
            ], 20.0);
            self::assertArrayNotHasKey('error', $typeDefinition, $client->stderr());
            self::assertSame(
                $this->fileUri($fixture . '/var/json_schema/body-type-demo.json'),
                $typeDefinition['result']['uri'] ?? null,
                json_encode($typeDefinition, JSON_UNESCAPED_SLASHES) . "\n" . $client->stderr(),
            );

            $shutdown = $client->request('shutdown', [], 10.0);
            self::assertArrayNotHasKey('error', $shutdown, $client->stderr());
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

        return $this->positionAtOffset($offset, $source);
    }

    /**
     * @return array{line: int, character: int}
     */
    private function positionAtOffset(int $offset, string $source): array
    {
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
