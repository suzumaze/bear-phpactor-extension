<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\LanguageServer;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;

use function Amp\Promise\wait;

final class SemanticConsistencyTest extends TestCase
{
    public function testAliasesShareInventoryDescriptionReferencesAndContracts(): void
    {
        $handler = new SemanticQueryHandler(dirname(__DIR__, 2) . '/Fixture/SemanticConsistency');
        $inventory = wait($handler->listResources(scheme: 'page'))['data']['resources'];
        self::assertCount(1, $inventory);
        $canonical = $inventory[0]['uri'];
        $referenceItems = null;
        foreach ([$canonical, 'page://self/content/special-page', 'page://self/specialPage'] as $uri) {
            $description = wait($handler->describeResource($uri));
            self::assertSame('ok', $description['status']);
            self::assertSame($canonical, $description['data']['resource']['canonicalUri']);
            self::assertSame($inventory[0]['path'], $description['data']['resource']['path']);
            $references = wait($handler->findResourceReferences($uri));
            self::assertSame(2, $references['data']['total']);
            $items = $references['data']['references'];
            if ($referenceItems !== null) {
                self::assertSame($referenceItems, $items);
            }
            $referenceItems = $items;
            foreach ($description['data']['schemas'] as $schema) {
                $lookup = wait($handler->describeResourceSchema($uri, $schema['kind'], method: $schema['method']));
                $resolve = wait($handler->resolveResourceSchema($uri, $schema['kind'], method: $schema['method']));
                foreach ([$lookup, $resolve] as $result) {
                    self::assertSame('ok', $result['status']);
                    self::assertSame($schema['path'], $result['data']['path']);
                    self::assertSame($schema['selection'], $result['data']['selection']);
                }
                $comparison = wait($handler->compareContract($uri, $schema['method'], $schema['kind']));
                self::assertContains($schema['path'], array_column($comparison['provenance'], 'path'));
            }
            self::assertCount(3, $description['data']['schemas']);
            $fallback = wait($handler->describeResourceSchema($uri, method: 'onPost'));
            self::assertSame('ok', $fallback['status']);
            self::assertSame('bc_fallback', $fallback['data']['selection']);
            self::assertSame('src/Resource/Page/Content/SpecialPage.json', $fallback['data']['path']);
        }
    }
}
