<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Di\Composition;

use PHPUnit\Framework\Attributes\DataProvider;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ModuleInterpreter;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ObjectValue;

final class UnknownPropagationTest extends CompositionTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function unknownCases(): iterable
    {
        yield 'and' => ['shortAnd', 'branch_condition_unknown'];
        yield 'or' => ['shortOr', 'branch_condition_unknown'];
        yield 'coalesce' => ['coalesce', 'target_unknown'];
        yield 'optional extension' => ['optionalExtension', 'branch_condition_unknown'];
        yield 'install helper' => ['installHelper', 'install_module_unknown'];
        yield 'override helper' => ['overrideHelper', 'override_module_unknown'];
    }

    #[DataProvider('unknownCases')]
    public function testUnknownConditionsNeverProduceConfidentBindings(string $method, string $reason): void
    {
        $module = $this->interpreter->newObject(self::CLAIM . 'ReviewModule');
        self::assertInstanceOf(ObjectValue::class, $module);
        $this->interpreter->callMethod($module, $method, [], null);

        self::assertSame([], $this->interpreter->containerOf($module)->bindings);
        self::assertSame([$reason], array_column($this->interpreter->unknowns, 'reason'));
        self::assertSame(self::CLAIM . 'ReviewModule', $this->interpreter->unknowns[0]['module']);
        self::assertGreaterThan(0, $this->interpreter->unknowns[0]['line']);
    }

    /** @return iterable<string, array{string, array<string, string>, ?string}> */
    public static function knownCases(): iterable
    {
        yield 'and unset' => ['shortAnd', [], null];
        yield 'and set' => ['shortAnd', ['FEATURE' => '1'], 'Enabled'];
        yield 'or unset' => ['shortOr', [], 'Fallback'];
        yield 'or set' => ['shortOr', ['FEATURE' => '1'], null];
        yield 'missing Composer class' => ['missingProjectClass', [], 'Fallback'];
        yield 'coalesce set' => ['coalesce', ['TARGET' => 'Chosen'], 'Chosen'];
    }

    /** @param array<string, string> $environment */
    #[DataProvider('knownCases')]
    public function testKnownConditionsRetainPhpSemantics(string $method, array $environment, ?string $target): void
    {
        $interpreter = new ModuleInterpreter($this->classes, $environment);
        $module = $interpreter->newObject(self::CLAIM . 'ReviewModule');
        self::assertInstanceOf(ObjectValue::class, $module);
        $interpreter->callMethod($module, $method, [], null);

        self::assertSame($target === null ? [] : [
            'Service-' => ['kind' => 'dependency', 'target' => $target],
        ], $interpreter->containerOf($module)->bindings);
        self::assertSame([], $interpreter->unknowns);
    }
}
