<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\LanguageServer;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;

use function Amp\Promise\wait;

final class CanonicalUriTest extends TestCase
{
    public function testRouteAndReferencesExposeTheInventoryIdentityWithoutErasingInput(): void
    {
        $handler = new SemanticQueryHandler(dirname(__DIR__, 2) . '/Fixture/CanonicalUri');
        $route = wait($handler->resolveRoute('/specialPage'));
        self::assertSame('ok', $route['status']);
        self::assertSame('page://self/content/specialPage', $route['data']['canonicalUri']);
        self::assertSame('page://self/specialPage', $route['data']['routeUri']);
        self::assertSame('heuristic', $route['data']['resource']['resolvedVia']);
        foreach (['page://self/specialPage', 'page://self/content/special-page'] as $uri) {
            $references = wait($handler->findResourceReferences($uri));
            self::assertSame(2, $references['data']['total']);
            self::assertSame($uri, $references['data']['resource']['uri']);
            self::assertSame($route['data']['canonicalUri'], $references['data']['resource']['canonicalUri']);
        }
        $direct = wait($handler->resolveResource('page://self/content/special-page'));
        self::assertSame('direct', $direct['data']['resolvedVia']);
        $list = wait($handler->listResources(scheme: 'page', prefix: 'content/special'));
        self::assertSame($route['data']['canonicalUri'], $list['data']['resources'][0]['uri']);
    }
}
