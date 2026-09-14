<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Resource;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceReference;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceReferences;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceReferencesQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

final class ResourceReferencesQueryTest extends TestCase
{
    public function testFindsStaticUriReferencesDeterministically(): void
    {
        $result = (new ResourceReferencesQuery())->findInWorkspace(
            $this->fixtureContext(),
            'app://self/article',
            'src/Resource/App/Articles.php',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceReferences::class, $result->value);
        self::assertSame('app://self/article', $result->value->resource->uri->uri());
        self::assertSame(
            [
                'src/Resource/App/Articles.php',
                'src/Resource/Page/Admin/Article.php',
                'src/Resource/Page/Article.php',
            ],
            array_map($this->fixtureRelative(), $result->value->references),
        );
        self::assertSame(
            [
                ResourceReference::KIND_RESOURCE_URI,
                ResourceReference::KIND_RESOURCE_URI,
                ResourceReference::KIND_RESOURCE_URI,
            ],
            array_column($result->value->references, 'kind'),
        );
        self::assertSame(
            ['app://self/article{?id}', 'app://self/article', 'app://self/article'],
            array_column($result->value->references, 'identifier'),
        );
        $this->assertRangesSelectIdentifiers($result->value);
    }

    public function testFindsRouteAndUriReferencesToPageResource(): void
    {
        $result = (new ResourceReferencesQuery())->findInWorkspace(
            $this->fixtureContext(),
            'page://self/article',
            'src/Resource/App/PageCaller.php',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceReferences::class, $result->value);
        self::assertSame(
            ['aura.route.php', 'aura.route.php', 'src/Resource/App/PageCaller.php'],
            array_map($this->fixtureRelative(), $result->value->references),
        );
        self::assertSame(
            [ResourceReference::KIND_ROUTE, ResourceReference::KIND_ROUTE, ResourceReference::KIND_RESOURCE_URI],
            array_column($result->value->references, 'kind'),
        );
        self::assertSame(
            ['/article', '/article', 'page://self/article'],
            array_column($result->value->references, 'identifier'),
        );
        $this->assertRangesSelectIdentifiers($result->value);
    }

    public function testFindsReferencesForResourceClassFile(): void
    {
        $result = (new ResourceReferencesQuery())->findForFileInWorkspace(
            $this->fixtureContext(),
            'src/Resource/App/IndirectArticle.php',
            'src/Resource/App/IndirectArticle.php',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceReferences::class, $result->value);
        self::assertSame('app://self/indirectArticle', $result->value->resource->uri->uri());
        self::assertSame(
            ['src/Resource/App/IndirectCaller.php'],
            array_map($this->fixtureRelative(), $result->value->references),
        );
    }

    public function testDoesNotChooseAnAmbiguousResource(): void
    {
        $result = (new ResourceReferencesQuery())->findInWorkspace(
            $this->fixtureContext(),
            'page://self/x',
            'src/Resource/App/AmbiguousPage.php',
        );

        self::assertSame(SemanticStatus::Ambiguous, $result->status);
        self::assertNull($result->value);
        self::assertCount(2, $result->candidates);
        self::assertContainsOnlyInstancesOf(ResourceReferences::class, $result->candidates);
        self::assertSame(
            [
                'src/Resource/Page/Admin/X.php',
                'src/Resource/Page/Content/X.php',
            ],
            array_map(
                fn (ResourceReferences $candidate): string => $this->fixtureRelativePath(
                    $candidate->resource->file,
                ),
                $result->candidates,
            ),
        );
    }

    public function testRejectsInvalidAndMissingTargets(): void
    {
        $query = new ResourceReferencesQuery();

        $invalidUri = $query->findInWorkspace(
            $this->fixtureContext(),
            'app://self/../article',
            'src/Resource/App/Article.php',
        );
        self::assertSame(SemanticStatus::InvalidInput, $invalidUri->status);

        $missingUri = $query->findInWorkspace(
            $this->fixtureContext(),
            'app://self/missing',
            'src/Resource/App/Article.php',
        );
        self::assertSame(SemanticStatus::NotFound, $missingUri->status);

        $invalidFile = $query->findForFileInWorkspace(
            $this->fixtureContext(),
            '../outside/Article.php',
            'src/Resource/App/Article.php',
        );
        self::assertSame(SemanticStatus::InvalidInput, $invalidFile->status);
    }

    public function testScanRejectsOutsideAndOversizedSources(): void
    {
        $temporaryRoot = sys_get_temp_dir() . '/bear-resource-references-' . bin2hex(random_bytes(8));
        $workspace = $temporaryRoot . '/workspace';
        $outside = $temporaryRoot . '/outside';

        try {
            self::assertTrue(mkdir($workspace . '/src/Resource/App', 0777, true));
            self::assertTrue(mkdir($workspace . '/src/Caller', 0777, true));
            self::assertTrue(mkdir($outside, 0777, true));
            self::assertNotFalse(file_put_contents(
                $workspace . '/composer.json',
                json_encode([
                    'autoload' => [
                        'psr-4' => [
                            'Acme\\App\\' => 'src/',
                            'Outside\\' => $outside,
                        ],
                    ],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ));
            self::assertNotFalse(file_put_contents(
                $workspace . '/src/Resource/App/User.php',
                '<?php final class User {}',
            ));
            self::assertNotFalse(file_put_contents(
                $workspace . '/src/Caller/Good.php',
                "<?php \$uri = 'app://self/user';",
            ));
            self::assertNotFalse(file_put_contents(
                $outside . '/Outside.php',
                "<?php \$uri = 'app://self/user';",
            ));
            self::assertTrue(symlink(
                $outside . '/Outside.php',
                $workspace . '/src/Caller/Escape.php',
            ));
            self::assertNotFalse(file_put_contents(
                $workspace . '/src/Caller/Large.php',
                "<?php \$uri = 'app://self/user';" . str_repeat(' ', 1_048_576),
            ));

            $context = WorkspaceContext::fromRoot($workspace);
            self::assertInstanceOf(WorkspaceContext::class, $context->value);
            $result = (new ResourceReferencesQuery())->findInWorkspace(
                $context->value,
                'app://self/user',
                'src/Caller/Good.php',
            );

            self::assertSame(SemanticStatus::Ok, $result->status);
            self::assertInstanceOf(ResourceReferences::class, $result->value);
            self::assertSame(
                [realpath($workspace . '/src/Caller/Good.php')],
                array_column($result->value->references, 'file'),
            );
        } finally {
            $this->removeTree($temporaryRoot);
        }
    }

    private function fixtureContext(): WorkspaceContext
    {
        $context = WorkspaceContext::fromRoot($this->fixtureDir());
        self::assertInstanceOf(WorkspaceContext::class, $context->value);

        return $context->value;
    }

    /** @return callable(ResourceReference): string */
    private function fixtureRelative(): callable
    {
        return fn (ResourceReference $reference): string => $this->fixtureRelativePath($reference->file);
    }

    private function fixtureRelativePath(string $path): string
    {
        return substr($path, strlen($this->fixtureDir()) + 1);
    }

    private function fixtureDir(): string
    {
        return dirname(__DIR__, 3) . '/Fixture/References';
    }

    private function assertRangesSelectIdentifiers(ResourceReferences $references): void
    {
        foreach ($references->references as $reference) {
            $source = (string) file_get_contents($reference->file);
            self::assertSame(
                $reference->identifier,
                substr($source, $reference->contentStart, $reference->contentEnd - $reference->contentStart),
            );
        }
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
