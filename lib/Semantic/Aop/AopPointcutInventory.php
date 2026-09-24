<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Aop;

/**
 * Bounded inventory of source-declared Ray.Aop pointcuts.
 */
final readonly class AopPointcutInventory
{
    /** @param list<AopPointcutFact> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $offset,
        public bool $truncated,
        public int $scannedModules,
        public int $unresolved,
        public ?string $interceptor,
    ) {
    }
}
