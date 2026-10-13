<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Schema;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;

use function Amp\Promise\wait;

final class SchemaDirectoriesTest extends TestCase
{
    public function testConfiguredDirectoriesShareDiContextPrecedenceAcrossTools(): void
    {
        $handler = $this->handler();
        foreach (
            [
            'app' => 'config/schema/record.json', 'prod-app' => 'config/production/record.json',
            ] as $ctx => $path
        ) {
            $lookup = wait($handler->describeResourceSchema(
                'app://self/example',
                method: 'onGet',
                applicationContext: $ctx,
            ));
            self::assertSame('ok', $lookup['status']);
            self::assertSame($path, $lookup['data']['path']);
            self::assertSame('configured', $lookup['data']['directorySelection']['status']);
            $description = wait($handler->describeResource('app://self/example', applicationContext: $ctx));
            self::assertSame('ok', $description['status']);
            self::assertContains($path, array_column($description['data']['schemas'], 'path'));
            self::assertContains('config/input/entry.json', array_column($description['data']['schemas'], 'path'));
            $compare = wait($handler->compareContract('app://self/example', applicationContext: $ctx));
            self::assertContains($path, array_column($compare['provenance'], 'path'));
            $binding = wait($handler->lookupDiBinding($ctx, name: 'json_schema_dir'));
            self::assertSame(
                $binding['data']['items'][0]['selected']['origin']['path'],
                $lookup['data']['directorySelection']['candidates'][0]['origin']['path'],
            );
            self::assertArrayNotHasKey('schemaDirectory', $binding['data']['items'][0]['selected']);
            $coverage = wait($handler->inspectContractCoverage(applicationContext: $ctx));
            self::assertContains($path, array_column($coverage['provenance'], 'path'));
            $diagnostics = wait($handler->diagnoseProject(applicationContext: $ctx));
            $mismatches = array_filter($diagnostics['data']['items'], static fn ($item): bool =>
                $item['code'] === 'contract_name_mismatch');
            self::assertCount($ctx === 'app' ? 0 : 1, $mismatches);
            self::assertNotContains('schema_reference_not_found', array_column($diagnostics['data']['items'], 'code'));
            self::assertNotContains('request_schema_references', $diagnostics['data']['skippedChecks']);
        }
    }

    public function testUnselectedContextsRemainAmbiguousAndDoNotUseConventionDecoy(): void
    {
        $handler = $this->handler();
        $lookup = wait($handler->describeResourceSchema('app://self/example', method: 'onGet'));
        self::assertSame('ambiguous', $lookup['status']);
        self::assertSame(
            ['config/schema/record.json', 'config/production/record.json'],
            array_column($lookup['candidates'], 'path'),
        );
        $description = wait($handler->describeResource('app://self/example'));
        self::assertSame('ok', $description['status']);
        $diagnostics = wait($handler->diagnoseProject());
        self::assertContains('contract_schema_unresolved', $diagnostics['data']['skippedChecks']);
        self::assertContains('ambiguous', array_column($description['data']['schemasCoverage']['items'], 'status'));
    }

    public function testDynamicEmptyAndOutsideSettingsNeverFallBack(): void
    {
        foreach (
            [
            'dynamic-app' => 'unsupported', 'empty-app' => 'unsupported', 'escape-app' => 'outside_workspace',
            ] as $ctx => $status
        ) {
            $result = wait($this->handler()->describeResourceSchema(
                'app://self/example',
                method: 'onGet',
                applicationContext: $ctx,
            ));
            self::assertSame($status, $result['status']);
            self::assertNull($result['data']);
            self::assertNotEmpty($result['coverage']['schemaDirectories']);
        }
    }

    public function testNamedArgumentsLegacyModuleAndMagicDirectoriesAreResolved(): void
    {
        $result = wait($this->handler()->describeResourceSchema(
            'app://self/example',
            method: 'onGet',
            applicationContext: 'named-app',
        ));
        self::assertSame('ok', $result['status']);
        self::assertSame('config/schema/record.json', $result['data']['path']);
        self::assertSame('explicit', $result['data']['directorySelection']['contextSelection']);
        self::assertFalse($result['data']['directorySelection']['runtimeObserved']);
        $unknown = wait($this->handler()->describeResourceSchema(
            'app://self/example',
            method: 'onGet',
            applicationContext: 'missing-app',
        ));
        self::assertSame('unsupported', $unknown['status']);
        self::assertFalse($unknown['coverage']['schemaDirectories']['contextDiscoveryComplete']);
        $invalid = wait($this->handler()->describeResourceSchema(
            'app://self/example',
            method: 'onGet',
            applicationContext: '../app',
        ));
        self::assertSame('invalid_input', $invalid['status']);
    }

    public function testDirectoryPathsUseTheSelectedProjectInsideALargerWorkspace(): void
    {
        $handler = new SemanticQueryHandler(dirname(__DIR__, 3) . '/Fixture');
        $result = wait($handler->describeResourceSchema(
            'app://self/example',
            contextPath: 'SchemaDirectories/src/Resource/App/Example.php',
            method: 'onGet',
            applicationContext: 'app',
        ));
        self::assertSame('ok', $result['status']);
        self::assertSame('SchemaDirectories/config/schema/record.json', $result['data']['path']);
    }

    private function handler(): SemanticQueryHandler
    {
        return new SemanticQueryHandler(dirname(__DIR__, 3) . '/Fixture/SchemaDirectories');
    }
}
