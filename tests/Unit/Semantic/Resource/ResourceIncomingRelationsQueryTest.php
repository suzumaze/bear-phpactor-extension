<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Resource;

use Suzumaze\BearPhpactor\Semantic\Resource\ResourceIncomingRelations;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceIncomingRelationsQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use PHPUnit\Framework\TestCase;

final class ResourceIncomingRelationsQueryTest extends TestCase
{
    private string $temporaryRoot;
    private string $workspace;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/bear-incoming-relations-' . bin2hex(random_bytes(8));
        $this->workspace = $this->temporaryRoot . '/workspace';
        self::assertTrue(mkdir($this->workspace . '/src/Resource/App', 0777, true));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/composer.json',
            '{"autoload":{"psr-4":{"Acme\\\\App\\\\":"src/"}}}',
        ));
        $this->writeResource('User.php', 'final class User extends ResourceObject {}');
        $this->writeResource('Orphan.php', 'final class Orphan extends ResourceObject {}');
        $this->writeResource('Dashboard.php', <<<'PHP'
final class Dashboard extends ResourceObject
{
    #[Embed(rel: 'user', src: 'app://self/user{?id}')]
    #[Link(rel: 'edit', href: '/user', method: 'patch')]
    #[Link(rel: 'dynamic', href: SOME_URI)]
    public function onGet(): static { return $this; }
}
PHP);
        $this->writeResource('Profile.php', <<<'PHP'
final class Profile extends ResourceObject
{
    #[Embed(rel: 'owner', src: 'app://self/user')]
    #[Embed(rel: 'other', src: 'app://self/orphan')]
    public function onGet(): static { return $this; }
}
PHP);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryRoot);
    }

    public function testFindsAndBoundsIncomingRelationsDeterministically(): void
    {
        $query = new ResourceIncomingRelationsQuery();
        $first = $query->findInWorkspace($this->workspace(), 'app://self/user', limit: 2);
        $second = $query->findInWorkspace($this->workspace(), 'app://self/user', limit: 2);

        self::assertSame(SemanticStatus::Ok, $first->status);
        self::assertInstanceOf(ResourceIncomingRelations::class, $first->value);
        self::assertTrue($first->value->available);
        self::assertSame(3, $first->value->total);
        self::assertTrue($first->value->truncated);
        self::assertSame(
            ['app://self/dashboard', 'app://self/dashboard'],
            array_map(static fn ($relation): string => $relation->sourceUri->uri(), $first->value->relations),
        );
        self::assertSame(
            ['embed:onGet:onGet', 'link:onGet:onPatch'],
            array_map(
                static fn ($relation): string => sprintf(
                    '%s:%s:%s',
                    $relation->kind,
                    $relation->sourceMethod,
                    $relation->targetMethod,
                ),
                $first->value->relations,
            ),
        );
        self::assertEquals($first, $second);
    }

    public function testReturnsAvailableEmptySetForUnreferencedResource(): void
    {
        $result = (new ResourceIncomingRelationsQuery())->findInWorkspace(
            $this->workspace(),
            'app://self/missing',
        );
        self::assertSame(SemanticStatus::NotFound, $result->status);

        $result = (new ResourceIncomingRelationsQuery())->findInWorkspace(
            $this->workspace(),
            'app://self/dashboard',
        );
        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceIncomingRelations::class, $result->value);
        self::assertTrue($result->value->available);
        self::assertSame([], $result->value->relations);
        self::assertSame(0, $result->value->total);
        self::assertFalse($result->value->truncated);
    }

    public function testRejectsInvalidLimitsAndPreservesAmbiguousTargets(): void
    {
        $query = new ResourceIncomingRelationsQuery();
        self::assertSame(
            SemanticStatus::InvalidInput,
            $query->findInWorkspace($this->workspace(), 'app://self/user', limit: 0)->status,
        );
        self::assertSame(
            SemanticStatus::InvalidInput,
            $query->findInWorkspace($this->workspace(), 'app://self/user', limit: 201)->status,
        );

        $workspace = WorkspaceContext::fromRoot(self::resourceFixture());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);
        $ambiguous = $query->findInWorkspace($workspace->value, 'page://self/x');
        self::assertSame(SemanticStatus::Ambiguous, $ambiguous->status);
        self::assertCount(2, $ambiguous->candidates);
        self::assertFalse($ambiguous->candidates[0]->available);
        self::assertFalse($ambiguous->candidates[1]->available);
    }

    public function testDoesNotScanResourceSymlinkOutsideWorkspace(): void
    {
        $outside = $this->temporaryRoot . '/Outside.php';
        self::assertNotFalse(file_put_contents(
            $outside,
            "<?php final class Escape extends \\BEAR\\Resource\\ResourceObject {}",
        ));
        self::assertTrue(symlink($outside, $this->workspace . '/src/Resource/App/Escape.php'));

        $result = (new ResourceIncomingRelationsQuery())->findInWorkspace(
            $this->workspace(),
            'app://self/user',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceIncomingRelations::class, $result->value);
        self::assertSame(3, $result->value->total);
    }

    private function workspace(): WorkspaceContext
    {
        $result = WorkspaceContext::fromRoot($this->workspace);
        self::assertInstanceOf(WorkspaceContext::class, $result->value);

        return $result->value;
    }

    private function writeResource(string $fileName, string $class): void
    {
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/src/Resource/App/' . $fileName,
            <<<PHP
<?php
namespace Acme\App\Resource\App;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\Annotation\Link;
use BEAR\Resource\ResourceObject;

{$class}
PHP,
        ));
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
