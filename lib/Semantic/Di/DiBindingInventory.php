<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

/**
 * Bounded inventory of source-declared Ray.Di bindings.
 */
final readonly class DiBindingInventory
{
    /** @param list<DiBindingFact> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $offset,
        public bool $truncated,
        public int $scannedModules,
        public int $unresolved,
        public ?string $type,
    ) {
    }
}
