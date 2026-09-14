<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Route;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Route\RouteQuery;
use Suzumaze\BearPhpactor\Semantic\Route\RouteResolution;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use PHPUnit\Framework\TestCase;

final class RouteQueryTest extends TestCase
{
    private Project $project;
    private RouteQuery $query;

    protected function setUp(): void
    {
        $project = Project::fromRoot(self::fixtureDir());
        self::assertNotNull($project);
        $this->project = $project;
        $this->query = new RouteQuery();
    }

    public function testResolvesRouteNameToPageResource(): void
    {
        $result = $this->query->resolve($this->project, '/thing/detail');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(RouteResolution::class, $result->value);
        self::assertSame('/thing/detail', $result->value->routeName);
        self::assertSame('page://self/thing/detail', $result->value->resource->uri->uri());
        self::assertSame(
            self::fixtureDir() . '/lib/Resource/Page/Thing/Detail.php',
            $result->value->resource->file,
        );
    }

    public function testDistinguishesInvalidMissingAndAmbiguousRouteNames(): void
    {
        $invalid = $this->query->resolve($this->project, 'index');
        $traversal = $this->query->resolve($this->project, '/../../Client');
        $missing = $this->query->resolve($this->project, '/missing');
        $ambiguous = $this->query->resolve($this->project, '/ambiguous');

        self::assertSame(SemanticStatus::InvalidInput, $invalid->status);
        self::assertSame(SemanticStatus::InvalidInput, $traversal->status);
        self::assertSame(SemanticStatus::NotFound, $missing->status);
        self::assertSame(SemanticStatus::Ambiguous, $ambiguous->status);
        self::assertSame(
            [
                self::fixtureDir() . '/lib/Resource/Page/Admin/Ambiguous.php',
                self::fixtureDir() . '/lib/Resource/Page/Content/Ambiguous.php',
            ],
            array_map(
                static fn (RouteResolution $candidate): string => $candidate->resource->file,
                $ambiguous->candidates,
            ),
        );
    }

    public function testResolvesThroughExplicitHeadlessWorkspace(): void
    {
        $workspace = WorkspaceContext::fromRoot(self::fixtureDir());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = $this->query->resolveInWorkspace(
            $workspace->value,
            '/index',
            'aura.route.php',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(RouteResolution::class, $result->value);
        self::assertSame(
            self::fixtureDir() . '/lib/Resource/Page/Index.php',
            $result->value->resource->file,
        );
    }

    private static function fixtureDir(): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/Router');
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
