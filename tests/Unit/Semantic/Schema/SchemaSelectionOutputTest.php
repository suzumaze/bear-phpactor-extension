<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Schema;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;

use function Amp\Promise\wait;

final class SchemaSelectionOutputTest extends TestCase
{
    public function testDescriptionSharesGroupedCandidatesAndPreservesEveryContext(): void
    {
        $handler = new SemanticQueryHandler(dirname(__DIR__, 3) . '/Fixture/SchemaContextMany');
        $description = wait($handler->describeResource('app://self/example'));
        self::assertSame('ok', $description['status']);
        self::assertCount(6, $description['data']['schemas']);
        $definitions = $description['data']['schemasCoverage']['directorySelections'] ?? [];
        self::assertCount(2, $definitions);
        foreach ([...$description['data']['schemas'], ...$description['data']['schemasCoverage']['items']] as $item) {
            $selection = $item['directorySelection'];
            self::assertArrayNotHasKey('candidates', $selection);
            self::assertTrue($selection['provisional']);
            self::assertArrayHasKey($selection['ref'], $definitions);
        }
        $response = array_values(array_filter($description['data']['schemas'], static fn ($schema): bool =>
            $schema['kind'] === 'response'))[0];
        $definition = $definitions[$response['directorySelection']['ref']];
        self::assertCount(1, $definition['candidateGroups']);
        self::assertSame(20, $definition['candidateCount']);
        $group = $definition['candidateGroups'][0];
        $lookup = wait($handler->describeResourceSchema('app://self/example', method: 'onGet'));
        $candidates = $lookup['data']['directorySelection']['candidates'];
        self::assertSame(array_column($candidates, 'applicationContext'), $group['applicationContexts']);
        foreach ($candidates as $candidate) {
            self::assertSame($candidate['directory'], $group['directory']);
            self::assertSame($candidate['origin'], $group['origin']);
        }
        self::assertLessThan(16 * 1024, strlen(json_encode($description, JSON_THROW_ON_ERROR)));
    }

    public function testAmbiguousAndUnsupportedSelectionsAlsoUseSharedDefinitions(): void
    {
        $handler = new SemanticQueryHandler(dirname(__DIR__, 3) . '/Fixture/SchemaDirectories');
        foreach ([null, 'dynamic-app'] as $context) {
            $result = wait($handler->describeResource('app://self/example', applicationContext: $context));
            self::assertSame('ok', $result['status']);
            $coverage = $result['data']['schemasCoverage'];
            foreach ($coverage['items'] as $item) {
                foreach ([$item, ...$item['candidates']] as $candidate) {
                    $selection = $candidate['directorySelection'];
                    if ($selection === null || $selection === []) {
                        continue;
                    }
                    self::assertArrayNotHasKey('candidates', $selection);
                    self::assertArrayHasKey($selection['ref'], $coverage['directorySelections']);
                }
            }
        }
    }
}
