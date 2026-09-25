<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Di;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Di\ContextModuleGraph;
use Suzumaze\BearPhpactor\Semantic\Di\ContextModuleGraphQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

final class ContextModuleGraphQueryTest extends TestCase
{
    public function testProjectsLiteralContextIntoWorkspaceModuleEdges(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->fixtureDir());
        self::assertNotNull($workspace->value);

        $result = (new ContextModuleGraphQuery())->describeInWorkspace($workspace->value, 'test-app');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ContextModuleGraph::class, $result->value);
        self::assertSame('test-app', $result->value->applicationContext);
        self::assertSame(['test', 'app'], array_map(
            static fn ($root): string => $root->segment,
            $result->value->segments,
        ));
        self::assertSame([
            'Acme\\DiAop\\Module\\TestModule',
            'Acme\\DiAop\\Module\\AppModule',
            'Acme\\DiAop\\Module\\FeatureModule',
        ], array_map(static fn ($module): string => $module->module, $result->value->modules));
        self::assertContains('install:workspace:Acme\\DiAop\\Module\\FeatureModule', array_map(
            static fn ($edge): string => $edge->kind . ':' . $edge->state . ':' . ($edge->target ?? ''),
            $result->value->edges,
        ));
        self::assertContains('extends:workspace:Acme\\DiAop\\Module\\AppModule', array_map(
            static fn ($edge): string => $edge->kind . ':' . $edge->state . ':' . ($edge->target ?? ''),
            $result->value->edges,
        ));
        self::assertFalse($result->value->truncated);
    }

    public function testRejectsInvalidContext(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->fixtureDir());
        self::assertNotNull($workspace->value);

        $result = (new ContextModuleGraphQuery())->describeInWorkspace($workspace->value, '../prod-app');

        self::assertSame(SemanticStatus::InvalidInput, $result->status);
    }

    private function fixtureDir(): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/DiAop');
        self::assertIsString($fixture);

        return $fixture;
    }
}
