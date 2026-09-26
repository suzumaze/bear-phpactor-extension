<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di\Composition;

/**
 * Ray.Di 2.23 Container and BindingLog write semantics, without instances.
 *
 * `add()` overwrites (last wins, logged as replace); `merge()` keeps existing
 * entries (`+=`, colliding incoming entries logged as keep); `move()` renames.
 */
final class EmulatedContainer
{
    public const MULTI_BINDINGS_INDEX = 'Ray\\Di\\MultiBinding\\MultiBindings-';

    /** @var array<string, array{kind: string, target: ?string, names?: string|array<string, string>}> */
    public array $bindings = [];

    /**
     * BindingLog events; `dependency` is the surviving binding, `lost` the replaced or
     * discarded one, and `from` the renamed index.
     *
     * @var list<array{
     *     type: string, index: string, dependency?: array{kind: string, target: ?string},
     *     source?: string, lost?: array{kind: string, target: ?string}, lostSource?: string, from?: string
     * }>
     */
    public array $events = [];

    /** @var array<string, string> index => owning module */
    public array $sources = [];

    /**
     * @param bool $rebindsMultiBindings whether the installed Ray.Di has
     *                                   Container::bindMergedMultiBindings() (2.23+)
     */
    public function __construct(private readonly bool $rebindsMultiBindings = true)
    {
    }

    /** @param array{kind: string, target: ?string, names?: string|array<string, string>} $dependency */
    public function add(string $index, array $dependency, string $source): void
    {
        $previous = $this->bindings[$index] ?? null;
        $previousSource = $this->sources[$index] ?? 'unknown';
        $this->bindings[$index] = $dependency;
        if ($index === self::MULTI_BINDINGS_INDEX) {
            return;
        }
        $event = [
            'type' => $previous === null ? 'bind' : 'replace',
            'index' => $index,
            'dependency' => $dependency,
            'source' => $source,
        ];
        if ($previous !== null) {
            $event += ['lost' => $previous, 'lostSource' => $previousSource];
        }
        $this->events[] = $event;
        $this->sources[$index] = $source;
    }

    public function merge(self $other): void
    {
        $colliding = array_keys(array_intersect_key($other->bindings, $this->bindings));
        $colliding = array_values(array_filter(
            $colliding,
            static fn (string $index): bool => $index !== self::MULTI_BINDINGS_INDEX,
        ));
        array_push($this->events, ...$other->events);
        foreach ($colliding as $index) {
            $this->events[] = [
                'type' => 'keep',
                'index' => $index,
                'dependency' => $this->bindings[$index],
                'source' => $this->sources[$index] ?? 'unknown',
                'lost' => $other->bindings[$index],
                'lostSource' => $other->sources[$index] ?? 'unknown',
            ];
        }
        $this->sources += array_diff_key($other->sources, array_fill_keys($colliding, true));
        $this->bindings += $other->bindings;
        // Container::bindMergedMultiBindings() rebinds the merged set as an instance.
        if ($this->rebindsMultiBindings && isset($this->bindings[self::MULTI_BINDINGS_INDEX])) {
            $this->bindings[self::MULTI_BINDINGS_INDEX] = self::multiBindingsInstance();
        }
    }

    /** @return array{kind: string, target: ?string} */
    public static function multiBindingsInstance(): array
    {
        return ['kind' => 'object', 'target' => 'Ray\\Di\\MultiBinding\\MultiBindings'];
    }

    public function move(string $from, string $to): bool
    {
        if (!isset($this->bindings[$from]) || ($from !== $to && isset($this->bindings[$to]))) {
            return false;
        }
        if ($from === $to) {
            return true;
        }
        $this->bindings[$to] = $this->bindings[$from];
        unset($this->bindings[$from]);
        if (isset($this->sources[$from])) {
            $this->sources[$to] = $this->sources[$from];
            unset($this->sources[$from]);
        }
        $this->events[] = [
            'type' => 'move',
            'index' => $to,
            'from' => $from,
            'source' => $this->sources[$to] ?? 'unknown',
        ];

        return true;
    }
}
