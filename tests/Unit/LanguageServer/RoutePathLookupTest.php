<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\LanguageServer;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;

use function Amp\Promise\wait;

final class RoutePathLookupTest extends TestCase
{
    public function testReadsConventionalRouteConfigAndReportsDeclarationCoverage(): void
    {
        $handler = new SemanticQueryHandler(dirname(__DIR__, 2) . '/Fixture/RoutePathsConfigured');
        foreach (['/latest', '/sitemap.xml'] as $path) {
            $result = wait($handler->resolveRoute($path, input: 'path'));
            self::assertSame('ok', $result['status']);
            $coverage = $result['coverage']['routing'];
            self::assertSame(['var/conf/aura.route.php'], $coverage['files']);
            self::assertSame(3, $coverage['declarations']);
            self::assertSame(2, $coverage['staticPaths']);
            self::assertSame(1, $coverage['patternPaths']);
            self::assertNotEmpty($coverage['unresolved']);
        }
    }

    public function testPathSearchUsesDefinitionsAndKeepsIdentitySeparate(): void
    {
        $handler = new SemanticQueryHandler(dirname(__DIR__, 2) . '/Fixture/RoutePaths');
        foreach (['/latest', '/named', '/custom'] as $path) {
            $result = wait($handler->resolveRoute($path, input: 'path', httpMethod: 'GET'));
            self::assertSame('ok', $result['status']);
            self::assertSame($path, $result['data']['path']);
            self::assertSame('page://self/content/listing', $result['data']['canonicalUri']);
            self::assertSame('heuristic', $result['data']['resource']['resolvedVia']);
            self::assertFalse($result['coverage']['routing']['runtimeObserved']);
            self::assertFalse($result['coverage']['routing']['complete']);
        }
        $get = wait($handler->resolveRoute('/entries', input: 'path', httpMethod: 'GET'));
        self::assertSame('/listing', $get['data']['route']);
        $post = wait($handler->resolveRoute('/entries', input: 'path', httpMethod: 'POST'));
        self::assertSame('/create', $post['data']['route']);
        $ambiguous = wait($handler->resolveRoute('/entries', input: 'path'));
        self::assertSame('ambiguous', $ambiguous['status']);
        self::assertCount(2, $ambiguous['candidates']);
        self::assertSame('ok', wait($handler->resolveRoute('/listing'))['status']);
    }

    public function testUnsupportedSyntaxNeverBecomesAnInventedRoute(): void
    {
        $handler = new SemanticQueryHandler(dirname(__DIR__, 2) . '/Fixture/RoutePaths');
        foreach (['/item/123', '/conditional', '/content/listing', '/absent'] as $path) {
            $result = wait($handler->resolveRoute($path, input: 'path'));
            self::assertSame('unsupported', $result['status']);
            self::assertNull($result['data']);
            self::assertNotEmpty($result['coverage']['routing']['unresolved']);
            self::assertSame('not_evaluated', $result['coverage']['routing']['runtimeRewrite']);
        }
        foreach (['https://example.org/latest', '//example.org/latest', '/latest?x=1'] as $path) {
            self::assertSame('invalid_input', wait($handler->resolveRoute($path, input: 'path'))['status']);
        }
    }
}
