<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\LanguageServer;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;

use function Amp\Promise\wait;

final class TemplateDependenciesTest extends TestCase
{
    public function testGraphRetainsConditionsDynamicEdgesAndResourceCalls(): void
    {
        $handler = new SemanticQueryHandler(dirname(__DIR__, 2) . '/Fixture/TemplateDependencies');
        $default = wait($handler->resolveResourceTemplate('page://self/example', 'twig'));
        self::assertArrayNotHasKey('dependencies', $default['data']);
        $result = wait($handler->resolveResourceTemplate('page://self/example', 'twig', includeDependencies: true));
        self::assertSame('ok', $result['status']);
        $graph = $result['data']['dependencies'];
        self::assertTrue($graph['available']);
        self::assertFalse($graph['runtimeObserved']);
        self::assertFalse($graph['complete']);
        self::assertCount(8, $graph['files']);
        $edges = $graph['edges'];
        $list = array_values(array_filter($edges, static fn ($e): bool =>
            $e['name'] === 'list.html.twig' && $e['source'] === 'var/templates/Page/Example.html.twig'))[0];
        self::assertTrue($list['conditional']);
        self::assertSame(['if'], $list['contexts']);
        self::assertSame('source_local', $list['conditionScope']);
        self::assertContains('cycle', array_column($edges, 'status'));
        self::assertSame(3, count(array_filter($edges, static fn ($e): bool => $e['status'] === 'dynamic')));
        self::assertContains('not_found', array_column($edges, 'status'));
        $byName = array_column($edges, null, 'name');
        self::assertTrue($byName['missing.html.twig']['ignoreMissing']);
        self::assertArrayNotHasKey('ignoreMissing', $byName['optional.html.twig']);
        foreach (['extends', 'include', 'embed', 'use', 'import', 'from'] as $kind) {
            self::assertContains($kind, array_column($edges, 'kind'));
        }
        self::assertCount(1, $graph['resources']);
        self::assertSame('page://self/fragment', $graph['resources'][0]['name']);
        self::assertTrue($graph['resources'][0]['conditional']);
        foreach (['comment.html.twig', 'verbatim.html.twig', 'quoted.html.twig', 'prefix'] as $ignored) {
            self::assertNotContains($ignored, array_column($graph['files'], 'path'));
        }
        self::assertFalse($graph['truncated']);
        self::assertNotContains('unbalanced_template_syntax', array_column($graph['unresolved'], 'reason'));
    }
    public function testDepthAndFileSizeLimitsRemainVisible(): void
    {
        $root = sys_get_temp_dir() . '/bear-template-depth-' . bin2hex(random_bytes(6));
        mkdir($root . '/var/templates', 0777, true);
        copy(dirname(__DIR__, 2) . '/Fixture/TemplateDependencies/composer.json', $root . '/composer.json');
        $workspace = \Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext::fromRoot($root)->value;
        self::assertNotNull($workspace);
        $uri = \Suzumaze\BearPhpactor\Resource\Model\ResourceUri::fromString('page://self/example');
        self::assertNotNull($uri);
        $resource = new \Suzumaze\BearPhpactor\Semantic\Resource\ResourceResolution($uri, __FILE__, 'Vendor\\App');
        try {
            for ($i = 0; $i < 23; ++$i) {
                file_put_contents(
                    $root . '/var/templates/' . $i . '.html.twig',
                    "{% include '" . ($i + 1) . ".html.twig' %}"
                );
            }
            $entry = new \Suzumaze\BearPhpactor\Semantic\Template\ResourceTemplateResolution(
                $resource,
                'twig',
                $root . '/var/templates/0.html.twig',
            );
            $query = new \Suzumaze\BearPhpactor\Semantic\Template\TemplateDependenciesQuery();
            $graph = $query->inspect($workspace, $entry);
            self::assertTrue($graph['truncated']);
            self::assertFalse($graph['complete']);
            self::assertContains('file_or_depth_limit', array_column($graph['unresolved'], 'reason'));
            file_put_contents($root . '/var/templates/0.html.twig', str_repeat('x', 262145));
            $graph = $query->inspect($workspace, $entry);
            self::assertTrue($graph['truncated']);
            self::assertSame([], $graph['files']);
            self::assertContains('unreadable_or_byte_limit', array_column($graph['unresolved'], 'reason'));
        } finally {
            foreach (glob($root . '/var/templates/*') ?: [] as $file) {
                unlink($file);
            }
            unlink($root . '/composer.json');
            rmdir($root . '/var/templates');
            rmdir($root . '/var');
            rmdir($root);
        }
    }
}
