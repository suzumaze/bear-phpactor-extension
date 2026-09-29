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
    public function testBuildsBoundedWorkspaceSourceMapWithoutAContext(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->fixtureDir());
        self::assertNotNull($workspace->value);

        $result = (new ContextModuleGraphQuery())->describeInWorkspace($workspace->value);

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ContextModuleGraph::class, $result->value);
        self::assertNull($result->value->applicationContext);
        self::assertTrue($result->value->workspaceSourceMap);
        self::assertSame([], $result->value->segments);
        self::assertSame(3, $result->value->totalModules);
        self::assertFalse($result->value->truncated);
        $names = array_map(static fn ($module): string => $module->module, $result->value->modules);
        self::assertSame([
            'Acme\\DiAop\\Module\\AppModule',
            'Acme\\DiAop\\Module\\FeatureModule',
            'Acme\\DiAop\\Module\\TestModule',
        ], $names);
        self::assertSame(3, $result->value->moduleMetadata['Acme\\DiAop\\Module\\AppModule']['bindingDeclarations']);
        self::assertSame(
            3,
            $result->value->moduleMetadata['Acme\\DiAop\\Module\\AppModule']['interceptorDeclarations'],
        );
        self::assertSame(
            1,
            $result->value->moduleMetadata['Acme\\DiAop\\Module\\TestModule']['interceptorDeclarations'],
        );
        self::assertSame(15, $result->value->moduleMetadata['Acme\\DiAop\\Module\\AppModule']['line']);
        self::assertContains('install:workspace:Acme\\DiAop\\Module\\FeatureModule', array_map(
            static fn ($edge): string => $edge->kind . ':' . $edge->state . ':' . ($edge->target ?? ''),
            $result->value->edges,
        ));
    }

    public function testProjectsLiteralContextIntoWorkspaceModuleEdges(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->fixtureDir());
        self::assertNotNull($workspace->value);

        $result = (new ContextModuleGraphQuery())->describeInWorkspace($workspace->value, 'test-app');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ContextModuleGraph::class, $result->value);
        self::assertSame('test-app', $result->value->applicationContext);
        self::assertFalse($result->value->workspaceSourceMap);
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

    public function testWorkspaceSourceMapKeepsInstallOverrideAndUnknownEdgesDistinct(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->fixtureDir('DiComposition'));
        self::assertNotNull($workspace->value);

        $result = (new ContextModuleGraphQuery())->describeInWorkspace($workspace->value);

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ContextModuleGraph::class, $result->value);
        $edges = array_map(
            static fn ($edge): string => $edge->kind . ':' . $edge->state . ':' . ($edge->target ?? ''),
            $result->value->edges,
        );
        self::assertContains(
            'override:workspace:Acme\\Shop\\Module\\Claim\\OverridingModule',
            $edges,
        );
        self::assertContains('install:unresolved:', $edges);
        self::assertSame($result->value->totalEdges, count($result->value->edges));
    }

    public function testRejectsInvalidContext(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->fixtureDir());
        self::assertNotNull($workspace->value);

        $result = (new ContextModuleGraphQuery())->describeInWorkspace($workspace->value, '../prod-app');

        self::assertSame(SemanticStatus::InvalidInput, $result->status);
    }

    private function fixtureDir(string $name = 'DiAop'): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/' . $name);
        self::assertIsString($fixture);

        return $fixture;
    }
}
