<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Di\Composition;

/**
 * Runtime-only values are recorded as unknowns and never turned into guessed bindings.
 */
final class UnknownBranchTest extends CompositionTestCase
{
    public function testEnvironmentIfRecordsTheBranchAndGuessesNoInstall(): void
    {
        [$s, $c] = [self::SERVICE, self::CLAIM];
        $container = $this->compose('EnvIfModule');

        self::assertSame([
            "{$s}ClockInterface-" => "dependency {$s}SystemClock @{$c}EnvIfModule",
        ], self::bindings($container));
        self::assertSame([[
            'reason' => 'branch_condition_unknown',
            'path' => 'src/Module/Claim/EnvIfModule.php',
            'line' => 15,
            'module' => "{$c}EnvIfModule",
        ]], $this->interpreter->unknowns);
    }

    public function testEnvironmentTernaryInstallingAModuleRecordsTheBranchWithItsLine(): void
    {
        $c = self::CLAIM;
        $container = $this->compose('EnvTernaryModule');

        // Neither arm's module is guessed into the container.
        self::assertSame([], self::bindings($container));
        self::assertContains([
            'reason' => 'branch_condition_unknown',
            'path' => 'src/Module/Claim/EnvTernaryModule.php',
            'line' => 13,
            'module' => "{$c}EnvTernaryModule",
        ], $this->interpreter->unknowns);
    }

    public function testTernaryTargetKeepsBothArmsAndTheEnvironmentVariableItReads(): void
    {
        [$s, $c] = [self::SERVICE, self::CLAIM];
        $container = $this->compose('EnvBranchTargetModule');

        $branches = [
            ['when' => 'condition_true', 'target' => "{$s}SecondThing"],
            ['when' => 'condition_false', 'target' => "{$s}FirstThing"],
        ];
        $condition = [
            'path' => 'src/Module/Claim/EnvBranchTargetModule.php',
            'line' => 18,
            'environment' => ['ACME_THING'],
        ];
        $dependency = $container->bindings["{$s}ThingInterface-"] ?? null;
        self::assertSame(
            ['kind' => 'dependency', 'target' => null, 'branches' => $branches, 'condition' => $condition],
            $dependency,
        );
        // Still an unknown: the source cannot say which arm runs.
        self::assertSame([[
            'reason' => 'target_branch',
            'path' => 'src/Module/Claim/EnvBranchTargetModule.php',
            'line' => 17,
            'module' => "{$c}EnvBranchTargetModule",
            'type' => "{$s}ThingInterface",
            'name' => '',
            'branches' => $branches,
            'condition' => $condition,
        ]], $this->interpreter->unknowns);
    }

    public function testTernaryProviderKeepsBothArms(): void
    {
        $s = self::SERVICE;
        $container = $this->compose('EnvBranchProviderModule');

        self::assertSame([
            'kind' => 'provider',
            'target' => null,
            'branches' => [
                ['when' => 'condition_true', 'target' => "{$s}FirstThing"],
                ['when' => 'condition_false', 'target' => "{$s}SecondThing"],
            ],
            'condition' => [
                'path' => 'src/Module/Claim/EnvBranchProviderModule.php',
                'line' => 16,
                'environment' => ['ACME_AUDIT'],
            ],
        ], $container->bindings["{$s}AuditInterface-"] ?? null);
        self::assertSame(['target_branch'], array_column($this->interpreter->unknowns, 'reason'));
    }

    public function testPureThrowGuardIsNotAnUnknown(): void
    {
        [$s, $c] = [self::SERVICE, self::CLAIM];
        $container = $this->compose('ThrowGuardModule');

        self::assertSame([
            "{$s}ClockInterface-" => "dependency {$s}SystemClock @{$c}ThrowGuardModule",
        ], self::bindings($container));
        self::assertSame([], $this->interpreter->unknowns);
    }

    public function testEnvironmentValuesStayUnknownButTypeNarrowingKeepsAString(): void
    {
        $c = self::CLAIM;
        $container = $this->compose('EnvValueModule');
        $owner = " @{$c}EnvValueModule";

        self::assertSame([
            '-dsn' => "string{$owner}",
            '-raw_dsn' => "unknown{$owner}",
        ], self::bindings($container));
        self::assertSame([], $this->interpreter->unknowns);
    }

    public function testTypeCheckOnATypedUnknownIsDecided(): void
    {
        [$s, $c] = [self::SERVICE, self::CLAIM];
        $container = $this->compose('TypedCheckModule');

        // `(string) getenv()` is a string whatever the environment holds, so is_string() is true.
        self::assertSame([
            "{$s}ClockInterface-" => "dependency {$s}SystemClock @{$c}TypedCheckModule",
        ], self::bindings($container));
        self::assertSame([], $this->interpreter->unknowns);
    }
}
