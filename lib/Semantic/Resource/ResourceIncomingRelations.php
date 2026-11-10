<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

/**
 * A bounded set of Link/Embed relations targeting one Resource.
 *
 * `available` is false for ambiguous target candidates because a BEAR URI
 * alone cannot identify which physical Resource is meant.
 *
 * `unresolved` lists Link/Embed attributes anywhere in the scanned Resources whose target could
 * not be read: any of them may point at this Resource, so `total` is a lower bound when it is not empty.
 */
final readonly class ResourceIncomingRelations
{
    /**
     * @param list<ResourceRelationFact> $relations
     * @param list<ResourceUnresolvedRelationFact> $unresolved first $limit of $unresolvedTotal
     */
    public function __construct(
        public ResourceResolution $resource,
        public bool $available,
        public array $relations = [],
        public int $total = 0,
        public bool $truncated = false,
        public array $unresolved = [],
        public int $unresolvedTotal = 0,
    ) {
    }

    public static function unavailable(ResourceResolution $resource): self
    {
        return new self($resource, false);
    }
}
