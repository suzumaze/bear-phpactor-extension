<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

/**
 * A bounded Resource attribute inventory for one workspace.
 */
final readonly class ResourceAttributeIndex
{
    /** @param list<ResourceAttributeIndexItem> $items */
    public function __construct(
        public array $items,
        public int $total,
        public bool $truncated,
    ) {
    }
}
