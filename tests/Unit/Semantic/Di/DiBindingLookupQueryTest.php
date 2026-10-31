<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Di;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;

use function Amp\Promise\wait;

final class DiBindingLookupQueryTest extends TestCase
{
    public function testLookupReturnsWinnerAndLossesWithLocationsWithoutInstanceValues(): void
    {
        $result = wait($this->handler()->lookupDiBinding('inspect-app', 'Acme\\Shop\\Service\\ThingInterface'));
        self::assertSame('ok', $result['status']);
        self::assertSame(1, $result['data']['total']);
        $item = $result['data']['items'][0];
        self::assertSame('Acme\\Shop\\Service\\OverridingThing', $item['selected']['target']);
        self::assertSame('source_selected', $item['selectionStatus']);
        self::assertSame('overridden', $item['decisions'][0]['reason']);
        self::assertSame('src/Module/Claim/OverridingModule.php', $item['selected']['origin']['path']);
        self::assertSame(['install', 'override'], array_column($item['selected']['origin']['via'], 'operation'));
        self::assertFalse($result['data']['coverage']['runtimeContainerObserved']);
        $scalar = wait($this->handler()->lookupDiBinding('inspect-app', '', 'api-key'));
        self::assertSame('string', $scalar['data']['items'][0]['selected']['kind']);
        self::assertNull($scalar['data']['items'][0]['selected']['target']);
        self::assertStringNotContainsString('fixture-private-value', json_encode($scalar, JSON_THROW_ON_ERROR));
    }

    public function testResourceOverrideFilterIsAppliedBeforePagination(): void
    {
        $result = wait($this->handler()->lookupDiBinding(
            'inspect-app',
            limit: 1,
            overridesOnly: true,
            resourcesOnly: true,
        ));
        self::assertSame(1, $result['data']['total']);
        self::assertSame('Acme\\Shop\\Resource\\Page\\Index', $result['data']['items'][0]['type']);
        self::assertFalse($result['data']['truncated']);
    }

    public function testUnknownBranchMakesSelectionsProvisionalAndDoesNotChooseAContext(): void
    {
        $result = wait($this->handler()->lookupDiBinding('uncertain-app', 'Acme\\Shop\\Service\\ClockInterface'));
        self::assertSame('provisional', $result['data']['items'][0]['selectionStatus']);
        self::assertGreaterThan(0, $result['data']['unknownTotal']);
        self::assertTrue($result['data']['coverage']['hasUnknowns']);
        $known = wait($this->handler()->lookupDiBinding('uncertain-app', environment: []));
        self::assertFalse($known['data']['coverage']['hasUnknowns']);
        self::assertSame('invalid_input', wait($this->handler()->lookupDiBinding(''))['status']);
    }

    public function testBranchTargetReplacesTheEarlierBindingAndShowsBothCandidates(): void
    {
        $clock = 'Acme\\Shop\\Service\\ClockInterface';
        $result = wait($this->handler()->lookupDiBinding('branch-app', $clock));
        self::assertSame(1, $result['data']['total']);
        $item = $result['data']['items'][0];
        self::assertSame('branch_dependent', $item['selectionStatus']);
        self::assertNull($item['selected']['target']);
        self::assertSame(
            ['Acme\\Shop\\Service\\FrozenClock', 'Acme\\Shop\\Service\\NetworkClock'],
            array_column($item['selected']['branches'], 'target'),
        );
        self::assertSame(['ACME_CLOCK'], $item['selected']['condition']['environment']);
        // The AppModule binding is no longer reported as the selection.
        self::assertSame('Acme\\Shop\\Service\\SystemClock', $item['decisions'][0]['discarded']['target']);
        self::assertTrue($result['data']['coverage']['hasUnknowns']);
        self::assertSame($clock, $result['data']['unknowns'][0]['type']);
        // The condition is located, not copied: its literal stays in the source.
        self::assertSame('src/Module/BranchModule.php', $item['selected']['condition']['path']);
        self::assertStringNotContainsString('frozen', json_encode($result, JSON_THROW_ON_ERROR));

        $frozen = wait(
            $this->handler()->lookupDiBinding('branch-app', $clock, environment: ['ACME_CLOCK' => 'frozen']),
        );
        self::assertSame('source_selected', $frozen['data']['items'][0]['selectionStatus']);
        self::assertSame('Acme\\Shop\\Service\\FrozenClock', $frozen['data']['items'][0]['selected']['target']);
        self::assertArrayNotHasKey('branches', $frozen['data']['items'][0]['selected']);

        // The container inventory reports the same key the same way.
        $inventory = wait($this->handler()->inspectDiContainer('branch-app', limit: 100));
        $items = array_column($inventory['data']['items'], null, 'index');
        self::assertSame(
            array_column($item['selected']['branches'], 'target'),
            array_column($items[$clock . '-']['branches'], 'target'),
        );
    }

    public function testResourcesOnlyKeepsAResourceBoundByABranch(): void
    {
        $advice = 'Acme\\Shop\\Resource\\App\\Advice';
        $result = wait($this->handler()->lookupDiBinding('branch-app', $advice, resourcesOnly: true));
        self::assertSame([$advice], array_column($result['data']['items'], 'type'));
        $item = $result['data']['items'][0];
        self::assertSame('branch_dependent', $item['selectionStatus']);
        self::assertSame(
            ['Acme\\Shop\\Resource\\App\\Replacement', 'Acme\\Shop\\Resource\\App\\Advice'],
            array_column($item['selected']['branches'], 'target'),
        );
    }

    public function testNestedProjectEvidenceUsesWorkspaceRelativePaths(): void
    {
        $handler = new SemanticQueryHandler(dirname(__DIR__, 3) . '/Fixture');
        $result = wait($handler->lookupDiBinding(
            'inspect-app',
            'Acme\\Shop\\Service\\ThingInterface',
            contextPath: 'DiComposition/src/Module/AppModule.php',
        ));
        self::assertSame('ok', $result['status']);
        $origin = $result['data']['items'][0]['selected']['origin'];
        self::assertSame('DiComposition/src/Module/Claim/OverridingModule.php', $origin['path']);
        self::assertSame('DiComposition/src/Module/InspectModule.php', $origin['via'][0]['path']);
    }

    private function handler(): SemanticQueryHandler
    {
        return new SemanticQueryHandler(dirname(__DIR__, 3) . '/Fixture/DiComposition');
    }
}
