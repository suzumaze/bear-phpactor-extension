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
