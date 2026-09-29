<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Di\Composition;

use Suzumaze\BearPhpactor\Semantic\Di\Composition\EmulatedContainer;

/**
 * One test per Ray.Di 2.23 write rule the module interpreter claims to reproduce.
 */
final class ModuleInterpreterTest extends CompositionTestCase
{
    public function testBindIsLastWinsAndLogsAReplace(): void
    {
        [$s, $c] = [self::SERVICE, self::CLAIM];
        $container = $this->compose('LastWinsModule');

        self::assertSame([
            "{$s}ThingInterface-" => "dependency {$s}SecondThing @{$c}LastWinsModule",
        ], self::bindings($container));
        self::assertSame([
            "bind {$s}ThingInterface-",
            "replace {$s}ThingInterface-",
        ], self::events($container));
        self::assertSame([], $this->interpreter->unknowns);
    }

    public function testInstallKeepsTheExistingBindingAndItsOwner(): void
    {
        [$s, $c] = [self::SERVICE, self::CLAIM];
        $container = $this->compose('InstallHostModule');

        self::assertSame([
            "{$s}ClockInterface-" => "dependency {$s}SystemClock @{$c}InstalledModule",
            "{$s}ThingInterface-" => "dependency {$s}FirstThing @{$c}InstallHostModule",
        ], self::bindings($container));
        // The installed module's own history is appended, then each collision becomes a keep.
        self::assertSame([
            "bind {$s}ThingInterface-",
            "bind {$s}ThingInterface-",
            "bind {$s}ClockInterface-",
            "keep {$s}ThingInterface-",
        ], self::events($container));
    }

    public function testConstructorChainingMergesAfterConfigureWithExistingWins(): void
    {
        [$s, $c] = [self::SERVICE, self::CLAIM];
        $container = $this->compose('ChainOuterModule', 'ChainInnerModule');

        self::assertSame([
            "{$s}ClockInterface-" => "dependency {$s}SystemClock @{$c}ChainInnerModule",
            "{$s}ThingInterface-" => "dependency {$s}FirstThing @{$c}ChainOuterModule",
        ], self::bindings($container));
        self::assertSame([
            "bind {$s}ThingInterface-",
            "bind {$s}ThingInterface-",
            "bind {$s}ClockInterface-",
            "keep {$s}ThingInterface-",
        ], self::events($container));
    }

    public function testOverrideContainerAbsorbsOursAndBecomesOurs(): void
    {
        [$s, $c] = [self::SERVICE, self::CLAIM];
        $container = $this->compose('OverrideHostModule');

        self::assertSame([
            "{$s}ClockInterface-" => "dependency {$s}SystemClock @{$c}OverrideHostModule",
            "{$s}ThingInterface-" => "dependency {$s}OverridingThing @{$c}OverridingModule",
            // Bound after override(): it lands in the adopted container.
            'Psr\\Log\\LoggerInterface-' => "dependency {$s}FileLogger @{$c}OverrideHostModule",
        ], self::bindings($container));
        self::assertSame([
            "bind {$s}ThingInterface-",
            "bind {$s}ThingInterface-",
            "bind {$s}ClockInterface-",
            "keep {$s}ThingInterface-",
            'bind Psr\\Log\\LoggerInterface-',
        ], self::events($container));
    }

    public function testRenameMovesAnIndexInTheLastModuleAndTransfersItsOwner(): void
    {
        [$s, $c] = [self::SERVICE, self::CLAIM];
        $container = $this->compose('RenameOuterModule', 'RenameInnerModule');

        self::assertSame([
            "{$s}ThingInterface-renamed" => "dependency {$s}FirstThing @{$c}RenameInnerModule",
        ], self::bindings($container));
        self::assertSame([
            "bind {$s}ThingInterface-original",
            "move {$s}ThingInterface-renamed",
        ], self::events($container));
        self::assertSame([], $this->interpreter->unknowns);
    }

    public function testUntargetedBindingNeedsAnInstantiableClassThatWasNotBoundYet(): void
    {
        [$s, $c] = [self::SERVICE, self::CLAIM];
        $container = $this->compose('UntargetedModule');
        $owner = " @{$c}UntargetedModule";

        // Abstract classes, interfaces, and classes already bound at bind() time register nothing.
        self::assertSame([
            "{$s}BoundService-" => "dependency {$s}SecondThing{$owner}",
            "{$s}ConcreteService-" => "dependency {$s}ConcreteService{$owner}",
            "{$s}ConcreteService-qualified" => "dependency {$s}FirstThing{$owner}",
            "{$s}NamedService-named" => "dependency {$s}NamedService{$owner}",
            "{$s}SingletonService-" => "dependency {$s}SingletonService{$owner}",
        ], self::bindings($container));
        self::assertSame([
            "bind {$s}ConcreteService-",
            "bind {$s}NamedService-named",
            "bind {$s}SingletonService-",
            "bind {$s}BoundService-",
            "bind {$s}ConcreteService-qualified",
        ], self::events($container));
    }

    public function testInterceptorClassesAreBoundToThemselves(): void
    {
        [$s, $c] = [self::SERVICE, self::CLAIM];
        $container = $this->compose('InterceptorModule');
        $owner = " @{$c}InterceptorModule";

        // bindInterceptor() skips the interface; bindPriorityInterceptor() binds every entry.
        self::assertSame([
            "{$s}PriorityInterceptor-" => "dependency {$s}PriorityInterceptor{$owner}",
            "{$s}TraceInterceptor-" => "dependency {$s}TraceInterceptor{$owner}",
        ], self::bindings($container));
        self::assertSame([
            "bind {$s}TraceInterceptor-",
            "bind {$s}PriorityInterceptor-",
        ], self::events($container));
    }

    public function testOwnerIsTheRuntimeClassWhoseConfigureRuns(): void
    {
        [$s, $c] = [self::SERVICE, self::CLAIM];
        self::assertSame([
            "{$s}ClockInterface-" => "dependency {$s}SystemClock @{$c}ChildConfigModule",
            "{$s}ThingInterface-" => "dependency {$s}FirstThing @{$c}ChildConfigModule",
        ], self::bindings($this->compose('ChildConfigModule')));
        self::assertSame([
            "{$s}ThingInterface-" => "dependency {$s}FirstThing @{$c}InheritingModule",
        ], self::bindings($this->compose('InheritingModule')));
    }

    public function testMultiBindingsIndexIsAnUnloggedObjectAcrossMerges(): void
    {
        $container = $this->compose('MultiHostModule');

        self::assertSame([
            EmulatedContainer::MULTI_BINDINGS_INDEX => 'object Ray\\Di\\MultiBinding\\MultiBindings @-',
        ], self::bindings($container));
        self::assertSame([], self::events($container));
        self::assertSame([], $container->sources);
    }
}
