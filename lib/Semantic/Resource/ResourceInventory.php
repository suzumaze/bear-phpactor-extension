<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

/**
 * A bounded, deterministic Resource inventory for one workspace.
 */
final readonly class ResourceInventory
{
    /**
     * @param list<ResourceResolution> $resources
     */
    public function __construct(
        public array $resources,
        public int $total,
        public int $offset,
        public bool $truncated,
    ) {
    }
}
