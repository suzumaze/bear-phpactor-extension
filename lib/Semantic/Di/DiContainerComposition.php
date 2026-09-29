<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

/**
 * A bounded page of the Ray.Di container composed from saved source for one context.
 */
final readonly class DiContainerComposition
{
    /**
     * @param list<array{
     *     index: string, type: string, name: string, kind: string, target: ?string, module: ?string
     * }> $items
     * @param array{bind: int, replace: int, keep: int, move: int} $events
     * @param array<string, int> $modules owning module => binding count
     * @param list<array{reason: string, path: string, line: int, module: string}> $unknowns
     */
    public function __construct(
        public string $applicationContext,
        public string $appName,
        public array $items,
        public int $total,
        public int $offset,
        public bool $truncated,
        public array $events,
        public array $modules,
        public array $unknowns,
    ) {
    }
}
