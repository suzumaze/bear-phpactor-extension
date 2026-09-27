<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di\Composition;

use Suzumaze\BearPhpactor\Semantic\Aop\ComposedPointcut;

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

    /** @var list<ComposedPointcut> */
    public array $pointcuts = [];

    /**
     * BindingLog events; `dependency` is the surviving binding, `lost` the replaced or
     * discarded one, and `from` the renamed index.
     *
     * @var list<array{
     *     type: string, index: string, dependency?: array{kind: string, target: ?string},
     *     source?: string, lost?: array{kind: string, target: ?string}, lostSource?: string, from?: string,
     *     origin?: BindingOrigin, lostOrigin?: BindingOrigin, reason?: string
     * }>
     */
    public array $events = [];

    /** @var array<string, BindingOrigin> index => declaration and composition path */
    public array $origins = [];

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
    public function add(string $index, array $dependency, string $source, ?BindingOrigin $origin = null): void
    {
        $previous = $this->bindings[$index] ?? null;
        $previousSource = $this->sources[$index] ?? 'unknown';
        $this->bindings[$index] = $dependency;
        if ($index === self::MULTI_BINDINGS_INDEX) {
            return;
        }
        $previousOrigin = $this->origins[$index] ?? new BindingOrigin($previousSource);
        $this->origins[$index] = $origin ?? new BindingOrigin($source);
        $event = [
            'type' => $previous === null ? 'bind' : 'replace',
            'index' => $index,
            'dependency' => $dependency,
            'source' => $source,
            'origin' => $this->origins[$index],
            'reason' => $previous === null ? 'declared' : 'replaced_by_later_binding',
        ];
        if ($previous !== null) {
            $event += ['lost' => $previous, 'lostSource' => $previousSource, 'lostOrigin' => $previousOrigin];
        }
        $this->events[] = $event;
        $this->sources[$index] = $source;
    }

    public function merge(
        self $other,
        ?ModuleEdge $edge = null,
        string $reason = 'kept_existing_binding',
    ): void {
        if ($edge !== null) {
            $other = $other->through($edge);
        }
        $colliding = array_keys(array_intersect_key($other->bindings, $this->bindings));
        $colliding = array_values(array_filter(
            $colliding,
            static fn (string $index): bool => $index !== self::MULTI_BINDINGS_INDEX,
        ));
        array_push($this->events, ...$other->events);
        array_push($this->pointcuts, ...$other->pointcuts);
        foreach ($colliding as $index) {
            $this->events[] = [
                'type' => 'keep',
                'index' => $index,
                'dependency' => $this->bindings[$index],
                'source' => $this->sources[$index] ?? 'unknown',
                'lost' => $other->bindings[$index],
                'lostSource' => $other->sources[$index] ?? 'unknown',
                'origin' => $this->origins[$index] ?? new BindingOrigin('unknown'),
                'lostOrigin' => $other->origins[$index] ?? new BindingOrigin('unknown'),
                'reason' => $reason,
            ];
        }
        $this->sources += array_diff_key($other->sources, array_fill_keys($colliding, true));
        $this->origins += $other->origins;
        $this->bindings += $other->bindings;
        // Container::bindMergedMultiBindings() rebinds the merged set as an instance.
        if ($this->rebindsMultiBindings && isset($this->bindings[self::MULTI_BINDINGS_INDEX])) {
            $this->bindings[self::MULTI_BINDINGS_INDEX] = self::multiBindingsInstance();
        }
    }

    /** Return a routed copy so installing the same object twice does not mutate earlier evidence. */
    public function through(ModuleEdge $edge): self
    {
        $copy = clone $this;
        $copy->traceThrough($edge);

        return $copy;
    }

    /** Add evidence without replacing the container identity shared by override() and its module. */
    public function traceThrough(ModuleEdge $edge): void
    {
        foreach ($this->pointcuts as $index => $pointcut) {
            $this->pointcuts[$index] = $pointcut->through($edge);
        }
        foreach ($this->origins as $index => $origin) {
            $this->origins[$index] = $origin->through($edge);
        }
        foreach ($this->events as &$event) {
            foreach (['origin', 'lostOrigin'] as $key) {
                if (isset($event[$key])) {
                    $event[$key] = $event[$key]->through($edge);
                }
            }
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
        if (isset($this->origins[$from])) {
            $this->origins[$to] = $this->origins[$from];
            unset($this->origins[$from]);
        }
        $this->events[] = [
            'type' => 'move',
            'index' => $to,
            'from' => $from,
            'origin' => $this->origins[$to] ?? new BindingOrigin('unknown'),
            'reason' => 'renamed',
            'source' => $this->sources[$to] ?? 'unknown',
        ];

        return true;
    }
}
