<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

/**
 * A bounded set of Link/Embed relations targeting one Resource.
 *
 * `available` is false for ambiguous target candidates because a BEAR URI
 * alone cannot identify which physical Resource is meant.
 */
final readonly class ResourceIncomingRelations
{
    /**
     * @param list<ResourceRelationFact> $relations
     */
    public function __construct(
        public ResourceResolution $resource,
        public bool $available,
        public array $relations = [],
        public int $total = 0,
        public bool $truncated = false,
    ) {
    }

    public static function unavailable(ResourceResolution $resource): self
    {
        return new self($resource, false);
    }
}
