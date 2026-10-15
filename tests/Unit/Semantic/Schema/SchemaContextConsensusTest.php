<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;

use function Amp\Promise\wait;

final class SchemaContextConsensusTest extends TestCase
{
    /** @return iterable<string, array{string, int, int, bool, int}> */
    public static function incompleteDiscovery(): iterable
    {
        yield 'context limit' => ['SchemaContextMany', 21, 20, true, 0];
        yield 'dynamic entry point' => ['SchemaContextUnresolved', 1, 1, false, 1];
    }

    #[DataProvider('incompleteDiscovery')]
    public function testKnownConsensusRemainsUsableAcrossAllTools(
        string $fixture,
        int $discovered,
        int $evaluated,
        bool $truncated,
        int $unresolved,
    ): void {
        $handler = new SemanticQueryHandler(dirname(__DIR__, 3) . '/Fixture/' . $fixture);
        $lookup = wait($handler->describeResourceSchema('app://self/example', 'request', method: 'onGet'));
        self::assertSame('ok', $lookup['status']);
        self::assertSame('schema/input.json', $lookup['data']['path']);
        $selection = $lookup['data']['directorySelection'];
        self::assertTrue($selection['provisional']);
        self::assertFalse($selection['contextDiscoveryComplete']);
        self::assertSame($discovered, $selection['contextDiscovery']['discoveredTotal']);
        self::assertSame($evaluated, $selection['contextDiscovery']['evaluated']);
        self::assertSame($truncated, $selection['contextDiscovery']['truncated']);
        self::assertSame($unresolved, $selection['contextDiscovery']['unresolvedTotal']);

        $description = wait($handler->describeResource('app://self/example'));
        self::assertCount(2, array_filter($description['data']['schemas'], static fn ($schema): bool =>
            $schema['method'] === 'onGet'));
        $compare = wait($handler->compareContract('app://self/example'));
        self::assertContains('schema/output.json', array_column($compare['provenance'], 'path'));
        $surfaces = array_column($compare['data']['surfaces'], null, 'source');
        self::assertTrue($surfaces['schema']['directorySelection']['provisional']);
        $coverage = wait($handler->inspectContractCoverage());
        self::assertSame('available', $coverage['data']['items'][0]['surfaces']['requestSchema']['state']);
        self::assertSame('available', $coverage['data']['items'][0]['surfaces']['responseSchema']['state']);
        $response = $coverage['data']['items'][0]['surfaces']['responseSchema'];
        self::assertFalse($response['directorySelection']['contextDiscoveryComplete']);
        $diagnostics = wait($handler->diagnoseProject());
        self::assertContains('contract_name_mismatch', array_column($diagnostics['data']['items'], 'code'));
        self::assertNotContains('contract_schema_unresolved', $diagnostics['data']['skippedChecks']);
        $mismatch = array_values(array_filter($diagnostics['data']['items'], static fn ($item): bool =>
            $item['code'] === 'contract_name_mismatch'))[0];
        self::assertTrue($mismatch['details']['directorySelection']['provisional']);
    }
}
