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
        yield 'install helper' => ['installHelper', 'branch_condition_unknown'];
        yield 'override helper' => ['overrideHelper', 'branch_condition_unknown'];
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

    public function testUnknownHelperBranchDoesNotSelectItsFallbackReturn(): void
    {
        $module = $this->interpreter->newObject(self::CLAIM . 'ReviewModule');
        self::assertInstanceOf(ObjectValue::class, $module);
        $this->interpreter->callMethod($module, 'helperChoice', [], null);

        self::assertSame([], $this->interpreter->containerOf($module)->bindings);
        self::assertSame('branch_condition_unknown', $this->interpreter->unknowns[0]['reason']);
        self::assertSame('src/Module/Claim/ReviewHelper.php', $this->interpreter->unknowns[0]['path']);
    }

    public function testKnownHelperBranchSelectsItsActualReturn(): void
    {
        $interpreter = new ModuleInterpreter($this->classes, ['FEATURE' => '1']);
        $module = $interpreter->newObject(self::CLAIM . 'ReviewModule');
        self::assertInstanceOf(ObjectValue::class, $module);
        $interpreter->callMethod($module, 'helperChoice', [], null);

        self::assertSame(
            ['Service-' => ['kind' => 'dependency', 'target' => 'Enabled']],
            $interpreter->containerOf($module)->bindings,
        );
        self::assertSame([], $interpreter->unknowns);
    }

    public function testUnknownTraitBranchIsRecorded(): void
    {
        $module = $this->interpreter->newObject(self::CLAIM . 'ReviewModule');
        self::assertInstanceOf(ObjectValue::class, $module);
        $this->interpreter->callMethod($module, 'traitChoice', [], null);

        self::assertSame([], $this->interpreter->containerOf($module)->bindings);
        self::assertSame('src/Module/Claim/ReviewTrait.php', $this->interpreter->unknowns[0]['path']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsupportedCallbackCases(): iterable
    {
        yield 'closure' => ['closureChoice', 'callable_unsupported'];
        yield 'arrow' => ['arrowChoice', 'callable_unsupported'];
        yield 'array_map' => ['callbackChoice', 'callback_unsupported'];
    }

    #[DataProvider('unsupportedCallbackCases')]
    public function testInvokedCallbacksRemainExplicitlyUnknown(string $method, string $reason): void
    {
        $module = $this->interpreter->newObject(self::CLAIM . 'ReviewModule');
        self::assertInstanceOf(ObjectValue::class, $module);
        $this->interpreter->callMethod($module, $method, [], null);

        self::assertSame([], $this->interpreter->containerOf($module)->bindings);
        self::assertContains($reason, array_column($this->interpreter->unknowns, 'reason'));
    }

    public function testRetainedBindCanAcquireQualifierBeforeItsTarget(): void
    {
        $module = $this->interpreter->newObject(self::CLAIM . 'ReviewModule');
        self::assertInstanceOf(ObjectValue::class, $module);
        $this->interpreter->callMethod($module, 'delayedQualifier', [], null);

        self::assertSame(
            ['Service-qualified' => ['kind' => 'dependency', 'target' => 'Enabled']],
            $this->interpreter->containerOf($module)->bindings,
        );
    }

    public function testStringQualifierKeepsItsLeadingBackslash(): void
    {
        $module = $this->interpreter->newObject(self::CLAIM . 'ReviewModule');
        self::assertInstanceOf(ObjectValue::class, $module);
        $this->interpreter->callMethod($module, 'literalQualifier', [], null);

        self::assertArrayHasKey('Service-\\Fx\\Q', $this->interpreter->containerOf($module)->bindings);
    }
}
