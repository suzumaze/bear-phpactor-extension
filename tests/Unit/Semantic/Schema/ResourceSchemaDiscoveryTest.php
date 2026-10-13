<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Schema;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;

use function Amp\Promise\wait;

final class ResourceSchemaDiscoveryTest extends TestCase
{
    public function testDescribeLookupAndCompareShareMethodAttributeSelection(): void
    {
        $handler = $this->handler();
        $description = wait($handler->describeResource('app://self/example'));
        $schemas = $description['data']['schemas'];
        self::assertContains('var/json_validate/input.json', array_column($schemas, 'path'));
        $paths = ['request' => 'var/json_validate/input.json', 'response' => 'var/json_schema/explicit.json'];
        foreach ($paths as $kind => $path) {
            $lookup = wait($handler->describeResourceSchema('app://self/example', $kind, method: 'onGet'));
            self::assertSame('ok', $lookup['status']);
            self::assertSame($path, $lookup['data']['path']);
            self::assertSame('attribute', $lookup['data']['selection']);
            self::assertSame('onGet', $lookup['data']['method']);
            $compare = wait($handler->compareContract('app://self/example', 'onGet', $kind));
            self::assertContains($path, array_column($compare['provenance'], 'path'));
        }
        $ambiguous = wait($handler->describeResourceSchema('app://self/example', 'request'));
        self::assertSame('ambiguous', $ambiguous['status']);
        $positional = wait($handler->describeResourceSchema('app://self/example', 'request', method: 'onPost'));
        self::assertSame('var/json_validate/second-input.json', $positional['data']['path']);
        self::assertFalse($description['data']['schemasCoverage']['runtimeObserved']);
    }

    public function testDynamicSelectionDoesNotFallBackAndConventionsAreDistinguished(): void
    {
        $handler = $this->handler();
        $dynamic = wait($handler->describeResourceSchema('app://self/example', method: 'onPatch'));
        self::assertSame('unsupported', $dynamic['status']);
        $fallback = wait($handler->describeResourceSchema('app://self/example', method: 'onPut'));
        self::assertSame('src/Resource/App/Example.json', $fallback['data']['path']);
        self::assertSame('bc_fallback', $fallback['data']['selection']);
        $convention = wait($handler->describeResourceSchema('app://self/example', method: 'onDelete'));
        self::assertSame('var/json_schema/example.json', $convention['data']['path']);
        self::assertSame('convention_only', $convention['data']['selection']);
        $missing = wait($handler->describeResourceSchema('app://self/example', method: 'onMissing'));
        self::assertSame('not_found', $missing['status']);
    }

    private function handler(): SemanticQueryHandler
    {
        return new SemanticQueryHandler(dirname(__DIR__, 3) . '/Fixture/SchemaDiscovery');
    }
}
