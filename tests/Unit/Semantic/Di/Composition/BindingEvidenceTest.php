<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Di\Composition;

final class BindingEvidenceTest extends CompositionTestCase
{
    public function testLaterBindingKeepsBothDeclarationSites(): void
    {
        $container = $this->compose('LastWinsModule');
        $decision = $container->events[1];
        self::assertSame('replaced_by_later_binding', $decision['reason']);
        self::assertSame(self::SERVICE . 'SecondThing', $decision['dependency']['target']);
        self::assertSame(self::SERVICE . 'FirstThing', $decision['lost']['target']);
        self::assertSame('src/Module/Claim/LastWinsModule.php', $decision['origin']->path);
        self::assertGreaterThan($decision['lostOrigin']->line, $decision['origin']->line);
    }

    public function testInstallExplainsDiscardedDeclarationAndImportSite(): void
    {
        $container = $this->compose('InstallHostModule');
        $decision = $container->events[3];
        self::assertSame('kept_existing_binding', $decision['reason']);
        self::assertSame(self::CLAIM . 'InstalledModule', $decision['lostOrigin']->module);
        $edge = $decision['lostOrigin']->via[0];
        self::assertSame('install', $edge->operation);
        self::assertSame('src/Module/Claim/InstallHostModule.php', $edge->path);
        self::assertNotNull($edge->line);
        self::assertSame([], $decision['origin']->via);
    }

    public function testOverrideCarriesPathOnlyToTheImportedWinner(): void
    {
        $container = $this->compose('OverrideHostModule');
        $decision = $container->events[3];
        self::assertSame('overridden', $decision['reason']);
        self::assertSame('override', $decision['origin']->via[0]->operation);
        self::assertSame('src/Module/Claim/OverrideHostModule.php', $decision['origin']->via[0]->path);
        self::assertSame([], $decision['lostOrigin']->via);
        self::assertSame('src/Module/Claim/OverridingModule.php', $decision['origin']->path);
        // The declaration after override belongs to the host and is not imported through override.
        self::assertSame([], $container->origins['Psr\\Log\\LoggerInterface-']->via);
    }

    public function testEvidenceDoesNotDetachTheOverridingModuleContainer(): void
    {
        $container = $this->compose('AliasOverrideModule');
        self::assertSame('ChangedAfterOverride', $container->bindings[self::SERVICE . 'ThingInterface-']['target']);
        self::assertSame('override', $container->origins[self::SERVICE . 'ThingInterface-']->via[0]->operation);
    }

    public function testInheritedConfigureHasDeclaringFileAndOwningModule(): void
    {
        $origin = $this->compose('InheritingModule')->origins[self::SERVICE . 'ThingInterface-'];
        self::assertSame(self::CLAIM . 'InheritingModule', $origin->module);
        self::assertSame('src/Module/Claim/ParentConfigModule.php', $origin->path);
    }
}
