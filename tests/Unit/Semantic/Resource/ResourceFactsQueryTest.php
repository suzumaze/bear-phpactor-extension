<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Resource;

use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFacts;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Tests\Unit\Semantic\Resource\Support\CountingParser;
use PHPUnit\Framework\TestCase;

final class ResourceFactsQueryTest extends TestCase
{
    private string $temporaryRoot;
    private string $workspace;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/bear-resource-facts-' . bin2hex(random_bytes(8));
        $this->workspace = $this->temporaryRoot . '/workspace';
        self::assertTrue(mkdir($this->workspace . '/src/Resource/App', 0777, true));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/composer.json',
            '{"autoload":{"psr-4":{"Acme\\\\App\\\\":"src/"}}}',
        ));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/src/Resource/App/Dashboard.php',
            <<<'PHP'
<?php
namespace Acme\App\Resource\App;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\Annotation\Link;

final class Dashboard extends \BEAR\Resource\ResourceObject
{
    #[Embed(rel: 'user', src: 'app://self/user{?id}')]
    #[Link(rel: 'create', href: '/users', method: 'post')]
    #[Link(rel: 'dynamic', href: 'app://self/dynamic', method: SOME_METHOD)]
    public function onGet(int $id, ?string $name = null): static
    {
        return $this;
    }

    function onPost(array $body): static
    {
        return $this;
    }

    private function onDelete(): void {}
    public function onlyHelper(): void {}
}
PHP,
        ));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryRoot);
    }

    public function testDescribesMethodsParametersAndStaticRelations(): void
    {
        $result = (new ResourceFactsQuery())->describeInWorkspace(
            $this->workspace(),
            'app://self/dashboard',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceFacts::class, $result->value);
        self::assertSame(['onGet', 'onPost'], array_map(
            static fn ($method): string => $method->name,
            $result->value->methods,
        ));
        self::assertSame('id', $result->value->methods[0]->parameters[0]->name);
        self::assertSame('int', $result->value->methods[0]->parameters[0]->type);
        self::assertSame('name', $result->value->methods[0]->parameters[1]->name);
        self::assertSame('?string', $result->value->methods[0]->parameters[1]->type);

        self::assertSame(
            ['app://self/dynamic', 'app://self/user', 'app://self/users'],
            array_map(
                static fn ($relation): string => $relation->targetUri->uri(),
                $result->value->outgoingRelations,
            ),
        );
        self::assertNull($result->value->outgoingRelations[0]->targetMethod);
        self::assertSame('onGet', $result->value->outgoingRelations[1]->targetMethod);
        self::assertSame('onPost', $result->value->outgoingRelations[2]->targetMethod);
        self::assertSame('onGet', $result->value->outgoingRelations[2]->sourceMethod);
    }

    public function testIgnoresClassAttributesAndUnsupportedDynamicTargets(): void
    {
        $file = $this->workspace . '/src/Resource/App/Dashboard.php';
        $source = (string) file_get_contents($file);
        $source = str_replace(
            'final class Dashboard',
            "#[Embed(rel: 'classLevel', src: 'app://self/class-level')]\nfinal class Dashboard",
            $source,
        );
        $source = str_replace(
            "#[Embed(rel: 'user', src: 'app://self/user{?id}')]",
            "#[Embed(rel: 'user', src: SOME_URI)]",
            $source,
        );
        self::assertNotFalse(file_put_contents($file, $source));

        $result = (new ResourceFactsQuery())->describeInWorkspace($this->workspace(), 'app://self/dashboard');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceFacts::class, $result->value);
        self::assertSame(
            ['app://self/dynamic', 'app://self/users'],
            array_map(
                static fn ($relation): string => $relation->targetUri->uri(),
                $result->value->outgoingRelations,
            ),
        );
    }

    public function testReturnsStructuredFailureForMissingAndOversizedSource(): void
    {
        $query = new ResourceFactsQuery();
        self::assertSame(
            SemanticStatus::NotFound,
            $query->describeInWorkspace($this->workspace(), 'app://self/missing')->status,
        );

        self::assertNotFalse(file_put_contents(
            $this->workspace . '/src/Resource/App/Dashboard.php',
            '<?php ' . str_repeat(' ', 2097153),
        ));
        self::assertSame(
            SemanticStatus::ParseError,
            $query->describeInWorkspace($this->workspace(), 'app://self/dashboard')->status,
        );
    }

    public function testPreservesAmbiguousResourceCandidatesInDeterministicOrder(): void
    {
        $workspace = WorkspaceContext::fromRoot(self::resourceFixture());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = (new ResourceFactsQuery())->describeInWorkspace($workspace->value, 'page://self/x');

        self::assertSame(SemanticStatus::Ambiguous, $result->status);
        self::assertSame(
            [
                self::resourceFixture() . '/src/Resource/Page/Admin/X.php',
                self::resourceFixture() . '/src/Resource/Page/Content/X.php',
            ],
            array_map(
                static fn (ResourceFacts $facts): string => $facts->resource->file,
                $result->candidates,
            ),
        );
        self::assertSame(
            [['onGet'], ['onGet']],
            array_map(
                static fn (ResourceFacts $facts): array => array_map(
                    static fn ($method): string => $method->name,
                    $facts->methods,
                ),
                $result->candidates,
            ),
        );
    }

    public function testCachesUnchangedSourceAndInvalidatesSameSizeSameTimestampChange(): void
    {
        $parser = new CountingParser();
        $query = new ResourceFactsQuery(parser: $parser);
        $file = $this->workspace . '/src/Resource/App/Dashboard.php';
        $modified = filemtime($file);
        self::assertIsInt($modified);

        $first = $query->describeInWorkspace($this->workspace(), 'app://self/dashboard');
        $second = $query->describeInWorkspace($this->workspace(), 'app://self/dashboard');
        self::assertSame(SemanticStatus::Ok, $first->status);
        self::assertSame(SemanticStatus::Ok, $second->status);
        self::assertSame(1, $parser->parseCount);

        $source = (string) file_get_contents($file);
        $changed = str_replace('app://self/user{?id}', 'app://self/team{?id}', $source);
        self::assertSame(strlen($source), strlen($changed));
        self::assertNotFalse(file_put_contents($file, $changed));
        self::assertTrue(touch($file, $modified));

        $third = $query->describeInWorkspace($this->workspace(), 'app://self/dashboard');
        self::assertSame(SemanticStatus::Ok, $third->status);
        self::assertSame(2, $parser->parseCount);
        self::assertInstanceOf(ResourceFacts::class, $third->value);
        self::assertContains(
            'app://self/team',
            array_map(static fn ($relation): string => $relation->targetUri->uri(), $third->value->outgoingRelations),
        );
    }

    public function testCachesParseErrorUntilSourceChanges(): void
    {
        $parser = new CountingParser();
        $query = new ResourceFactsQuery(parser: $parser);
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/src/Resource/App/Dashboard.php',
            '<?php echo "no class";',
        ));

        $first = $query->describeInWorkspace($this->workspace(), 'app://self/dashboard');
        $second = $query->describeInWorkspace($this->workspace(), 'app://self/dashboard');

        self::assertSame(SemanticStatus::ParseError, $first->status);
        self::assertSame(SemanticStatus::ParseError, $second->status);
        self::assertSame(1, $parser->parseCount);
    }

    private function workspace(): WorkspaceContext
    {
        $result = WorkspaceContext::fromRoot($this->workspace);
        self::assertInstanceOf(WorkspaceContext::class, $result->value);

        return $result->value;
    }

    private static function resourceFixture(): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/Resource');
        self::assertNotFalse($fixture);

        return $fixture;
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
