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
