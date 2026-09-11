<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Workspace;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use PHPUnit\Framework\TestCase;

final class WorkspaceContextTest extends TestCase
{
    public function testLocatesRootAndContextualProjectWithinWorkspace(): void
    {
        $context = $this->context(self::fixtureDir());

        $rootProject = $context->project();
        $sourceProject = $context->project('src/Client.php');

        self::assertSame(SemanticStatus::Ok, $rootProject->status);
        self::assertInstanceOf(Project::class, $rootProject->value);
        self::assertSame(self::fixtureDir(), $rootProject->value->root());
        self::assertSame(SemanticStatus::Ok, $sourceProject->status);
        self::assertInstanceOf(Project::class, $sourceProject->value);
        self::assertSame(self::fixtureDir(), $sourceProject->value->root());
    }

    public function testContextPathCannotEscapeWorkspace(): void
    {
        $context = $this->context(self::fixtureDir());

        self::assertSame(SemanticStatus::InvalidInput, $context->project('../composer.json')->status);
        self::assertSame(
            SemanticStatus::InvalidInput,
            $context->project(self::fixtureDir() . '/src/Client.php')->status,
        );
        self::assertSame(SemanticStatus::NotFound, $context->project('missing.php')->status);
    }

    public function testProjectLookupDoesNotClimbAboveNestedWorkspaceBoundary(): void
    {
        $nested = self::fixtureDir() . '/nested';
        $context = $this->context($nested);

        // nested/composer.json has no PSR-4 map. The unrestricted locator
        // historically continues to the parent fixture; WorkspaceContext must not.
        $project = $context->project('marker.txt');

        self::assertSame(SemanticStatus::NotFound, $project->status);
        self::assertNull($project->value);
    }

    public function testBoundedProjectLocatorSupportsANewUnsavedFilePath(): void
    {
        $project = Project::locateWithin(
            self::fixtureDir() . '/src/NewUnsavedResource.php',
            self::fixtureDir(),
        );

        self::assertInstanceOf(Project::class, $project);
        self::assertSame(self::fixtureDir(), $project->root());
    }

    private function context(string $root): WorkspaceContext
    {
        $result = WorkspaceContext::fromRoot($root);
        self::assertInstanceOf(WorkspaceContext::class, $result->value);

        return $result->value;
    }

    private static function fixtureDir(): string
    {
        return dirname(__DIR__, 3) . '/Fixture/Resource';
    }
}
