<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Di\Composition;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSourceIndex;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\EmulatedContainer;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ModuleInterpreter;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ObjectValue;

/**
 * Shared fixture access; expectations are hand-derived from Ray.Di 2.23.1 and BEAR.Package sources.
 */
abstract class CompositionTestCase extends TestCase
{
    protected const CLAIM = 'Acme\\Shop\\Module\\Claim\\';

    protected const SERVICE = 'Acme\\Shop\\Service\\';

    protected ClassSourceIndex $classes;

    protected ModuleInterpreter $interpreter;

    protected function setUp(): void
    {
        $this->classes = new ClassSourceIndex(self::fixtureRoot());
        $this->interpreter = new ModuleInterpreter($this->classes);
    }

    protected static function fixtureRoot(): string
    {
        $root = realpath(dirname(__DIR__, 4) . '/Fixture/DiComposition');
        self::assertIsString($root);

        return $root;
    }

    /** Composes `new $outer(new $inner())`, or `new $outer()` when no inner module is given. */
    protected function compose(string $outer, ?string $inner = null): EmulatedContainer
    {
        $arguments = $inner === null ? [] : [$this->interpreter->newObject(self::CLAIM . $inner)];
        $module = $this->interpreter->newObject(self::CLAIM . $outer, $arguments);
        self::assertInstanceOf(ObjectValue::class, $module);

        return $this->interpreter->containerOf($module);
    }

    /** @return array<string, string> index => "kind target @owner", sorted like bindings.md */
    protected static function bindings(EmulatedContainer $container): array
    {
        $bindings = [];
        foreach ($container->bindings as $index => $dependency) {
            $bindings[$index] = trim($dependency['kind'] . ' ' . ($dependency['target'] ?? ''))
                . ' @' . ($container->sources[$index] ?? '-');
        }
        ksort($bindings);

        return $bindings;
    }

    /** @return list<string> "type index" in log order */
    protected static function events(EmulatedContainer $container): array
    {
        return array_map(
            static fn (array $event): string => $event['type'] . ' ' . $event['index'],
            $container->events,
        );
    }
}
