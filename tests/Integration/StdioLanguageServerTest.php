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

    public function testRealPhpactorStdioInvalidatesResourceInventoryFromWatchedFileNotification(): void
    {
        $workspace = $this->runtimeDirectory . '/inventory-workspace';
        $resourceDirectory = $workspace . '/src/Resource/App';
        self::assertTrue(mkdir($resourceDirectory, 0777, true));
        self::assertNotFalse(file_put_contents(
            $workspace . '/composer.json',
            '{"autoload":{"psr-4":{"Acme\\\\Inventory\\\\":"src/"}}}',
        ));
        self::assertNotFalse(file_put_contents(
            $resourceDirectory . '/First.php',
            '<?php final class First extends \\BEAR\\Resource\\ResourceObject {}',
        ));
        $client = StdioLspClient::start(
            $this->command($workspace),
            $workspace,
            $this->environment(),
        );

        try {
            $initialize = $client->request('initialize', [
                'processId' => getmypid(),
                'rootUri' => $this->fileUri($workspace),
                'capabilities' => [
                    'workspace' => [
                        'didChangeWatchedFiles' => ['dynamicRegistration' => true],
                    ],
                ],
            ], 20.0);
            self::assertArrayNotHasKey('error', $initialize, $client->stderr());
            $client->notify('initialized');

            $first = $client->request('bear/resource/list', [], 20.0);
            self::assertSame(1, $first['result']['data']['total'] ?? null);

            $addedFile = $resourceDirectory . '/Second.php';
            self::assertNotFalse(file_put_contents(
                $addedFile,
                '<?php final class Second extends \\BEAR\\Resource\\ResourceObject {}',
            ));
            $beforeNotification = $client->request('bear/resource/list', [], 20.0);
            self::assertSame(1, $beforeNotification['result']['data']['total'] ?? null);

            $client->notify('workspace/didChangeWatchedFiles', [
                'changes' => [[
                    'uri' => $this->fileUri($addedFile),
                    'type' => 1,
                ]],
            ]);
            $afterNotification = $client->request('bear/resource/list', [], 20.0);
            self::assertArrayNotHasKey('error', $afterNotification, $client->stderr());
            self::assertSame(2, $afterNotification['result']['data']['total'] ?? null);
            self::assertSame(
                ['app://self/first', 'app://self/second'],
                array_column($afterNotification['result']['data']['resources'] ?? [], 'uri'),
            );

            self::assertNotFalse(file_put_contents(
                $addedFile,
                '<?php final class Second {}',
            ));
            $client->notify('textDocument/didSave', [
                'textDocument' => ['uri' => $this->fileUri($addedFile)],
            ]);
            $afterSave = $client->request('bear/resource/list', [], 20.0);
            self::assertArrayNotHasKey('error', $afterSave, $client->stderr());
            self::assertSame(1, $afterSave['result']['data']['total'] ?? null);

            self::assertTrue(unlink($resourceDirectory . '/First.php'));
            $client->notify('workspace/didChangeWatchedFiles', [
                'changes' => [[
                    'uri' => $this->fileUri($resourceDirectory . '/First.php'),
                    'type' => 3,
                ]],
            ]);
            $afterDelete = $client->request('bear/resource/list', [], 20.0);
            self::assertArrayNotHasKey('error', $afterDelete, $client->stderr());
            self::assertSame(0, $afterDelete['result']['data']['total'] ?? null);

            $shutdown = $client->request('shutdown', [], 10.0);
            self::assertArrayNotHasKey('error', $shutdown, $client->stderr());
            $client->notify('exit');
        } finally {
            $client->close();
        }
    }

    public function testRealPhpactorStdioFindsPageResourceReferencesFromRouteName(): void
    {
        $fixture = dirname(__DIR__) . '/Fixture/References';
        $routeFile = $fixture . '/aura.route.php';
        $source = (string) file_get_contents($routeFile);
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
            self::assertTrue($initialize['result']['capabilities']['referencesProvider'] ?? false);
            $client->notify('initialized');
            $client->notify('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => $this->fileUri($routeFile),
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => $source,
                ],
            ]);

            $references = $client->request('textDocument/references', [
                'textDocument' => ['uri' => $this->fileUri($routeFile)],
                'position' => $this->positionOf('/article', $source),
                'context' => ['includeDeclaration' => false],
            ], 20.0);
            self::assertArrayNotHasKey('error', $references, $client->stderr());
            $uris = array_column($references['result'] ?? [], 'uri');
            self::assertSame(2, count(array_filter(
                $uris,
                fn (string $uri): bool => $uri === $this->fileUri($routeFile),
            )));
            self::assertContains(
                $this->fileUri($fixture . '/src/Resource/App/PageCaller.php'),
                $uris,
            );

            $shutdown = $client->request('shutdown', [], 10.0);
            self::assertArrayNotHasKey('error', $shutdown, $client->stderr());
            $client->notify('exit');
        } finally {
            $client->close();
        }
    }

    public function testRealPhpactorStdioFindsSqlReferencesFromDbQueryId(): void
    {
        $fixture = dirname(__DIR__) . '/Fixture/Sql/App1';
        $queryFile = $fixture . '/src/Query/PointQueryInterface.php';
        $source = (string) file_get_contents($queryFile);
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
            self::assertTrue($initialize['result']['capabilities']['referencesProvider'] ?? false);
            $client->notify('initialized');
            $client->notify('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => $this->fileUri($queryFile),
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => $source,
                ],
            ]);

            $references = $client->request('textDocument/references', [
                'textDocument' => ['uri' => $this->fileUri($queryFile)],
                'position' => $this->positionOf('point_distance', $source),
                'context' => ['includeDeclaration' => false],
            ], 20.0);
            self::assertArrayNotHasKey('error', $references, $client->stderr());
            self::assertSame([
                $this->fileUri($fixture . '/src/Query/LegacyPointQueryInterface.php'),
                $this->fileUri($queryFile),
            ], array_column($references['result'] ?? [], 'uri'));

            $withDeclaration = $client->request('textDocument/references', [
                'textDocument' => ['uri' => $this->fileUri($queryFile)],
                'position' => $this->positionOf('point_distance', $source),
                'context' => ['includeDeclaration' => true],
            ], 20.0);
            self::assertArrayNotHasKey('error', $withDeclaration, $client->stderr());
            self::assertContains(
                $this->fileUri($fixture . '/var/db/sql/point_distance.sql'),
                array_column($withDeclaration['result'] ?? [], 'uri'),
            );

            $shutdown = $client->request('shutdown', [], 10.0);
            self::assertArrayNotHasKey('error', $shutdown, $client->stderr());
            $client->notify('exit');
        } finally {
            $client->close();
        }
    }

    public function testRealPhpactorStdioFindsExplicitJsonSchemaReferences(): void
    {
        $fixture = dirname(__DIR__) . '/Fixture/JsonSchema/basic';
        $sourceFile = $fixture . '/src/SchemaReferences.php';
        $source = (string) file_get_contents($sourceFile);
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
            self::assertTrue($initialize['result']['capabilities']['referencesProvider'] ?? false);
            $client->notify('initialized');
            $client->notify('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => $this->fileUri($sourceFile),
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => $source,
                ],
            ]);

            $references = $client->request('textDocument/references', [
                'textDocument' => ['uri' => $this->fileUri($sourceFile)],
                'position' => $this->positionOf('user.json', $source),
                'context' => ['includeDeclaration' => false],
            ], 20.0);
            self::assertArrayNotHasKey('error', $references, $client->stderr());
            self::assertSame(
                [$this->fileUri($sourceFile), $this->fileUri($sourceFile)],
                array_column($references['result'] ?? [], 'uri'),
            );
            self::assertSame(
                [10, 13],
                array_map(
                    static fn (array $location): int => $location['range']['start']['line'],
                    $references['result'] ?? [],
                ),
            );

            $withDeclaration = $client->request('textDocument/references', [
                'textDocument' => ['uri' => $this->fileUri($sourceFile)],
                'position' => $this->positionOf('user.json', $source),
                'context' => ['includeDeclaration' => true],
            ], 20.0);
            self::assertArrayNotHasKey('error', $withDeclaration, $client->stderr());
            self::assertContains(
                $this->fileUri($fixture . '/var/json_schema/user.json'),
                array_column($withDeclaration['result'] ?? [], 'uri'),
            );

            $shutdown = $client->request('shutdown', [], 10.0);
            self::assertArrayNotHasKey('error', $shutdown, $client->stderr());
            $client->notify('exit');
        } finally {
            $client->close();
        }
    }

    public function testRealPhpactorStdioFindsAlpsDescriptorAttributeReferences(): void
    {
        $fixture = dirname(__DIR__) . '/Fixture/Alps/App1';
        $sourceFile = $fixture . '/src/AlpsReferences.php';
        $source = (string) file_get_contents($sourceFile);
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
            self::assertTrue($initialize['result']['capabilities']['referencesProvider'] ?? false);
            $client->notify('initialized');
            $client->notify('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => $this->fileUri($sourceFile),
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => $source,
                ],
            ]);

            $references = $client->request('textDocument/references', [
                'textDocument' => ['uri' => $this->fileUri($sourceFile)],
                'position' => $this->positionOf('doDeleteArticle', $source),
                'context' => ['includeDeclaration' => false],
            ], 20.0);
            self::assertArrayNotHasKey('error', $references, $client->stderr());
            self::assertSame(
                [$this->fileUri($sourceFile), $this->fileUri($sourceFile)],
                array_column($references['result'] ?? [], 'uri'),
            );
            self::assertSame(
                [10, 13],
                array_map(
                    static fn (array $location): int => $location['range']['start']['line'],
                    $references['result'] ?? [],
                ),
            );

            $withDeclaration = $client->request('textDocument/references', [
                'textDocument' => ['uri' => $this->fileUri($sourceFile)],
                'position' => $this->positionOf('doDeleteArticle', $source),
                'context' => ['includeDeclaration' => true],
            ], 20.0);
            self::assertArrayNotHasKey('error', $withDeclaration, $client->stderr());
            self::assertContains(
                $this->fileUri($fixture . '/var/alps/profile.json'),
                array_column($withDeclaration['result'] ?? [], 'uri'),
            );

            $shutdown = $client->request('shutdown', [], 10.0);
            self::assertArrayNotHasKey('error', $shutdown, $client->stderr());
            $client->notify('exit');
        } finally {
            $client->close();
        }
    }

    public function testRealPhpactorStdioFindsTwigAndQiqTemplateReferences(): void
    {
        $fixture = dirname(__DIR__) . '/Fixture/Template';
        $twigFile = $fixture . '/src/Resource/Page/TwigReferences.html.twig';
        $twigSource = (string) file_get_contents($twigFile);
        $qiqFile = $fixture . '/var/qiq/template/Page/QiqReferences.php';
        $qiqSource = (string) file_get_contents($qiqFile);
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
            self::assertTrue($initialize['result']['capabilities']['referencesProvider'] ?? false);
            $client->notify('initialized');
            $client->notify('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => $this->fileUri($twigFile),
                    'languageId' => 'twig',
                    'version' => 1,
                    'text' => $twigSource,
                ],
            ]);

            $twigReferences = $client->request('textDocument/references', [
                'textDocument' => ['uri' => $this->fileUri($twigFile)],
                'position' => $this->positionOf('element/component/card.html.twig', $twigSource),
                'context' => ['includeDeclaration' => false],
            ], 20.0);
            self::assertArrayNotHasKey('error', $twigReferences, $client->stderr());
            self::assertSame([
                $this->fileUri($twigFile),
                $this->fileUri($fixture . '/src/Resource/Page/TwigReferencesSecond.html.twig'),
            ], array_column($twigReferences['result'] ?? [], 'uri'));

            $withDeclaration = $client->request('textDocument/references', [
                'textDocument' => ['uri' => $this->fileUri($twigFile)],
                'position' => $this->positionOf('element/component/card.html.twig', $twigSource),
                'context' => ['includeDeclaration' => true],
            ], 20.0);
            self::assertArrayNotHasKey('error', $withDeclaration, $client->stderr());
            self::assertContains(
                $this->fileUri($fixture . '/var/templates/element/component/card.html.twig'),
                array_column($withDeclaration['result'] ?? [], 'uri'),
            );

            $client->notify('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => $this->fileUri($qiqFile),
                    'languageId' => 'qiq',
                    'version' => 1,
                    'text' => $qiqSource,
                ],
            ]);
            $qiqReferences = $client->request('textDocument/references', [
                'textDocument' => ['uri' => $this->fileUri($qiqFile)],
                'position' => $this->positionOf('partial/card', $qiqSource),
                'context' => ['includeDeclaration' => false],
            ], 20.0);
            self::assertArrayNotHasKey('error', $qiqReferences, $client->stderr());
            self::assertSame([
                $this->fileUri($qiqFile),
                $this->fileUri($qiqFile),
            ], array_column($qiqReferences['result'] ?? [], 'uri'));

            $shutdown = $client->request('shutdown', [], 10.0);
            self::assertArrayNotHasKey('error', $shutdown, $client->stderr());
            $client->notify('exit');
        } finally {
            $client->close();
        }
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
            self::assertTrue($initialize['result']['capabilities']['hoverProvider'] ?? false);

            $client->notify('initialized');

            $projectInfo = $client->request('bear/project/info', [], 20.0);
            self::assertArrayNotHasKey('error', $projectInfo, $client->stderr());
            self::assertSame('ok', $projectInfo['result']['status'] ?? null);
            self::assertSame('Resource', $projectInfo['result']['data']['workspaceName'] ?? null);
            self::assertSame('.', $projectInfo['result']['data']['projectPath'] ?? null);
            self::assertSame('composer.json', $projectInfo['result']['data']['composerPath'] ?? null);
            self::assertGreaterThan(0, $projectInfo['result']['data']['resourceCount'] ?? 0);
            self::assertContains(
                'resourceDescription',
                $projectInfo['result']['data']['capabilities'] ?? [],
            );

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
                'provenance' => [[
                    'source' => 'file',
                    'path' => 'src/Resource/App/User.php',
                    'freshness' => 'saved',
                ]],
            ], $semantic['result'] ?? null);

            $resourceReferences = $client->request('bear/resource/references', [
                'uri' => 'app://self/user',
                'contextPath' => 'src/Client.php',
                'limit' => 2,
            ], 20.0);
            self::assertArrayNotHasKey('error', $resourceReferences, $client->stderr());
            self::assertSame('ok', $resourceReferences['result']['status'] ?? null);
            self::assertSame(3, $resourceReferences['result']['data']['total'] ?? null);
            self::assertTrue($resourceReferences['result']['data']['truncated'] ?? false);
            self::assertSame([
                'src/Client.php',
                'src/Resource/App/Article.php',
            ], array_column($resourceReferences['result']['data']['references'] ?? [], 'path'));
            self::assertNotEmpty($resourceReferences['result']['provenance'] ?? []);

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
            self::assertTrue($description['result']['data']['relationsIn']['available'] ?? false);
            self::assertSame(1, $description['result']['data']['relationsIn']['total'] ?? null);
            self::assertFalse($description['result']['data']['relationsIn']['truncated'] ?? true);
            self::assertSame(
                'app://self/article',
                $description['result']['data']['relationsIn']['items'][0]['sourceUri'] ?? null,
            );
            self::assertSame([], $description['result']['data']['templates'] ?? null);
            self::assertSame([], $description['result']['data']['schemas'] ?? null);

            $incoming = $client->request('bear/resource/incomingRelations', [
                'uri' => 'app://self/user',
                'contextPath' => 'src/Client.php',
                'limit' => 1,
            ], 20.0);
            self::assertArrayNotHasKey('error', $incoming, $client->stderr());
            self::assertSame('ok', $incoming['result']['status'] ?? null);
            self::assertSame('app://self/user', $incoming['result']['data']['resource']['uri'] ?? null);
            self::assertSame(1, $incoming['result']['data']['total'] ?? null);
            self::assertFalse($incoming['result']['data']['truncated'] ?? true);
            self::assertSame(
                'src/Resource/App/Article.php',
                $incoming['result']['data']['items'][0]['sourcePath'] ?? null,
            );

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

            $hover = $client->request('textDocument/hover', [
                'textDocument' => ['uri' => $this->fileUri($clientFile)],
                'position' => $position,
            ], 20.0);
            self::assertArrayNotHasKey('error', $hover, $client->stderr());
            self::assertSame('markdown', $hover['result']['contents']['kind'] ?? null);
            self::assertStringContainsString(
                '**BEAR Resource** `app://self/user`',
                $hover['result']['contents']['value'] ?? '',
            );
            self::assertStringContainsString(
                '`Acme\\Blog\\Resource\\App\\User`',
                $hover['result']['contents']['value'] ?? '',
            );

            $phpHover = $client->request('textDocument/hover', [
                'textDocument' => ['uri' => $this->fileUri($clientFile)],
                'position' => $this->positionOf('fixtureUris', $source),
            ], 20.0);
            self::assertArrayNotHasKey('error', $phpHover, $client->stderr());
            self::assertNotNull($phpHover['result'] ?? null);
            self::assertStringNotContainsString(
                '**BEAR Resource**',
                $phpHover['result']['contents']['value'] ?? '',
            );

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

    public function testRealPhpactorStdioServerResolvesSchemaTypeDefinitionAndHover(): void
    {
        $fixture = dirname(__DIR__) . '/Fixture/JsonSchema/basic';
        $resourceFile = $fixture . '/src/Resource/App/BodyTypeDemo.php';
        $sourceWithCaret = (string) file_get_contents($resourceFile);
        $offset = strpos($sourceWithCaret, '<caret>');
        self::assertNotFalse($offset);
        $source = str_replace('<caret>', '', $sourceWithCaret);
        $explicitSchemaFile = $fixture . '/src/Resource/App/SchemaDemo.php';
        $explicitSchemaSource = str_replace(
            ['<caret-1>', '<caret-2>', '<caret-3>', '<caret-4>', '<caret-5>'],
            '',
            (string) file_get_contents($explicitSchemaFile),
        );
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

            $schemaFacts = $client->request('bear/schema/describeForResource', [
                'uri' => 'app://self/bodyTypeDemo',
                'contextPath' => 'src/Resource/App/BodyTypeDemo.php',
            ], 20.0);
            self::assertArrayNotHasKey('error', $schemaFacts, $client->stderr());
            self::assertSame('ok', $schemaFacts['result']['status'] ?? null);
            self::assertSame(
                'var/json_schema/body-type-demo.json',
                $schemaFacts['result']['data']['path'] ?? null,
            );
            self::assertSame([
                ['name' => 'name', 'required' => false, 'types' => ['string']],
            ], $schemaFacts['result']['data']['properties'] ?? null);

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

            $client->notify('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => $this->fileUri($explicitSchemaFile),
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => $explicitSchemaSource,
                ],
            ]);

            $schemaHover = $client->request('textDocument/hover', [
                'textDocument' => ['uri' => $this->fileUri($explicitSchemaFile)],
                'position' => $this->positionOf('user.json', $explicitSchemaSource),
            ], 20.0);
            self::assertArrayNotHasKey('error', $schemaHover, $client->stderr());
            self::assertSame('markdown', $schemaHover['result']['contents']['kind'] ?? null);
            self::assertStringContainsString(
                '**BEAR JSON Schema**',
                $schemaHover['result']['contents']['value'] ?? '',
            );
            self::assertStringContainsString(
                'Path: `var/json_schema/user.json`',
                $schemaHover['result']['contents']['value'] ?? '',
            );
            self::assertStringContainsString(
                '- `name`: `string` (required)',
                $schemaHover['result']['contents']['value'] ?? '',
            );

            $shutdown = $client->request('shutdown', [], 10.0);
            self::assertArrayNotHasKey('error', $shutdown, $client->stderr());
            $client->notify('exit');
        } finally {
            $client->close();
        }
    }

    public function testRealPhpactorStdioServerDescribesAlpsRelationships(): void
    {
        $fixture = dirname(__DIR__) . '/Fixture/Alps/App1';
        $resourceFile = $fixture . '/src/Resource/App/AlpsDemo.php';
        $source = str_replace(
            ['<caret-1>', '<caret-2>', '<caret-3>', '<caret-4>'],
            '',
            (string) file_get_contents($resourceFile),
        );
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
            $client->notify('initialized');

            $facts = $client->request('bear/alps/describeDescriptor', [
                'descriptorId' => 'goArticle',
                'contextPath' => 'src/Resource/App/AlpsDemo.php',
            ], 20.0);
            self::assertArrayNotHasKey('error', $facts, $client->stderr());
            self::assertSame('ok', $facts['result']['status'] ?? null);
            self::assertSame('var/alps/profile.json', $facts['result']['data']['profilePath'] ?? null);
            self::assertSame('safe', $facts['result']['data']['type'] ?? null);
            self::assertSame('rt', $facts['result']['data']['relationsOut'][0]['kind'] ?? null);
            self::assertSame('Article', $facts['result']['data']['relationsOut'][0]['targetId'] ?? null);
            self::assertSame('href', $facts['result']['data']['relationsIn'][0]['kind'] ?? null);
            self::assertSame('Article', $facts['result']['data']['relationsIn'][0]['sourceId'] ?? null);

            $client->notify('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => $this->fileUri($resourceFile),
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => $source,
                ],
            ]);

            $hover = $client->request('textDocument/hover', [
                'textDocument' => ['uri' => $this->fileUri($resourceFile)],
                'position' => $this->positionOf('goArticle', $source),
            ], 20.0);
            self::assertArrayNotHasKey('error', $hover, $client->stderr());
            self::assertSame('markdown', $hover['result']['contents']['kind'] ?? null);
            self::assertStringContainsString(
                '**ALPS descriptor** `goArticle`',
                $hover['result']['contents']['value'] ?? '',
            );
            self::assertStringContainsString(
                '- `rt` → `Article` (ok)',
                $hover['result']['contents']['value'] ?? '',
            );
            self::assertStringContainsString(
                '- `href` ← `Article` (ok)',
                $hover['result']['contents']['value'] ?? '',
            );

            $unresolvedHover = $client->request('textDocument/hover', [
                'textDocument' => ['uri' => $this->fileUri($resourceFile)],
                'position' => $this->positionOf('noSuchDescriptor', $source),
            ], 20.0);
            self::assertArrayNotHasKey('error', $unresolvedHover, $client->stderr());
            self::assertNull($unresolvedHover['result'] ?? null);

            $phpHover = $client->request('textDocument/hover', [
                'textDocument' => ['uri' => $this->fileUri($resourceFile)],
                'position' => $this->positionOf('onGet', $source),
            ], 20.0);
            self::assertArrayNotHasKey('error', $phpHover, $client->stderr());
            self::assertNotNull($phpHover['result'] ?? null);
            self::assertStringNotContainsString(
                '**ALPS descriptor**',
                $phpHover['result']['contents']['value'] ?? '',
            );

            $shutdown = $client->request('shutdown', [], 10.0);
            self::assertArrayNotHasKey('error', $shutdown, $client->stderr());

            $afterShutdown = $client->request('textDocument/hover', [
                'textDocument' => ['uri' => $this->fileUri($resourceFile)],
                'position' => $this->positionOf('goArticle', $source),
            ], 10.0);
            self::assertSame(-32600, $afterShutdown['error']['code'] ?? null);
            $client->notify('exit');
        } finally {
            $client->close();
        }
    }

    public function testRealPhpactorStdioServerProvidesRouteHover(): void
    {
        $fixture = dirname(__DIR__) . '/Fixture/Router';
        $routeFile = $fixture . '/aura.route.php';
        $source = "<?php\n\$map->route('/index', '/index');\n";
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
            self::assertTrue($initialize['result']['capabilities']['hoverProvider'] ?? false);
            $client->notify('initialized');

            $client->notify('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => $this->fileUri($routeFile),
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => $source,
                ],
            ]);

            $hover = $client->request('textDocument/hover', [
                'textDocument' => ['uri' => $this->fileUri($routeFile)],
                'position' => $this->positionOf('/index', $source),
            ], 20.0);
            self::assertArrayNotHasKey('error', $hover, $client->stderr());
            self::assertSame('markdown', $hover['result']['contents']['kind'] ?? null);
            self::assertStringContainsString(
                '**BEAR Route** `/index`',
                $hover['result']['contents']['value'] ?? '',
            );
            self::assertStringContainsString(
                'Path: `lib/Resource/Page/Index.php`',
                $hover['result']['contents']['value'] ?? '',
            );

            $shutdown = $client->request('shutdown', [], 10.0);
            self::assertArrayNotHasKey('error', $shutdown, $client->stderr());
            $client->notify('exit');
        } finally {
            $client->close();
        }
    }

    public function testRealPhpactorStdioServerProvidesSqlHover(): void
    {
        $fixture = dirname(__DIR__) . '/Fixture/Sql/App1';
        $queryFile = $fixture . '/src/Query/PointQueryInterface.php';
        $source = "<?php\nuse Ray\\MediaQuery\\Annotation\\DbQuery;\n"
            . "#[DbQuery('point_distance')]\ninterface Query {}\n";
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
            self::assertTrue($initialize['result']['capabilities']['hoverProvider'] ?? false);
            $client->notify('initialized');

            $client->notify('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => $this->fileUri($queryFile),
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => $source,
                ],
            ]);

            $hover = $client->request('textDocument/hover', [
                'textDocument' => ['uri' => $this->fileUri($queryFile)],
                'position' => $this->positionOf('point_distance', $source),
            ], 20.0);
            self::assertArrayNotHasKey('error', $hover, $client->stderr());
            self::assertSame('markdown', $hover['result']['contents']['kind'] ?? null);
            self::assertStringContainsString(
                'Query ID: `point_distance`',
                $hover['result']['contents']['value'] ?? '',
            );
            self::assertStringContainsString(
                'Path: `var/db/sql/point_distance.sql`',
                $hover['result']['contents']['value'] ?? '',
            );

            $shutdown = $client->request('shutdown', [], 10.0);
            self::assertArrayNotHasKey('error', $shutdown, $client->stderr());
            $client->notify('exit');
        } finally {
            $client->close();
        }
    }

    public function testRealPhpactorStdioServerProvidesTwigAndQiqTemplateHover(): void
    {
        $fixture = dirname(__DIR__) . '/Fixture/Template';
        $twigFile = $fixture . '/src/Resource/Page/TwigReferences.html.twig';
        $twigSource = (string) file_get_contents($twigFile)
            . "\n{{ include('missing/static.html.twig') }}\n";
        $qiqFile = $fixture . '/var/qiq/template/Page/Nested/RelativeReferences.php';
        $qiqSource = (string) file_get_contents($qiqFile);
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
            self::assertTrue($initialize['result']['capabilities']['hoverProvider'] ?? false);
            $client->notify('initialized');

            $client->notify('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => $this->fileUri($twigFile),
                    'languageId' => 'twig',
                    'version' => 1,
                    'text' => $twigSource,
                ],
            ]);

            $twigHover = $client->request('textDocument/hover', [
                'textDocument' => ['uri' => $this->fileUri($twigFile)],
                'position' => $this->positionOf('element/component/card.html.twig', $twigSource),
            ], 20.0);
            self::assertArrayNotHasKey('error', $twigHover, $client->stderr());
            self::assertSame('markdown', $twigHover['result']['contents']['kind'] ?? null);
            self::assertStringContainsString(
                'Engine: `twig`',
                $twigHover['result']['contents']['value'] ?? '',
            );
            self::assertStringContainsString(
                'Path: `var/templates/element/component/card.html.twig`',
                $twigHover['result']['contents']['value'] ?? '',
            );

            $missingHover = $client->request('textDocument/hover', [
                'textDocument' => ['uri' => $this->fileUri($twigFile)],
                'position' => $this->positionOf('missing/static.html.twig', $twigSource),
            ], 20.0);
            self::assertArrayNotHasKey('error', $missingHover, $client->stderr());
            self::assertNull($missingHover['result'] ?? null);

            $client->notify('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => $this->fileUri($qiqFile),
                    'languageId' => 'qiq',
                    'version' => 1,
                    'text' => $qiqSource,
                ],
            ]);

            $qiqHover = $client->request('textDocument/hover', [
                'textDocument' => ['uri' => $this->fileUri($qiqFile)],
                'position' => $this->positionOf('./sibling', $qiqSource),
            ], 20.0);
            self::assertArrayNotHasKey('error', $qiqHover, $client->stderr());
            self::assertSame('markdown', $qiqHover['result']['contents']['kind'] ?? null);
            self::assertStringContainsString(
                'Engine: `qiq`',
                $qiqHover['result']['contents']['value'] ?? '',
            );
            self::assertStringContainsString(
                'Path: `var/qiq/template/Page/Nested/sibling.php`',
                $qiqHover['result']['contents']['value'] ?? '',
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
