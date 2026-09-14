<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

/**
 * A resolved Resource identity and every static source reference found for it.
 */
final readonly class ResourceReferences
{
    /** @param list<ResourceReference> $references */
    public function __construct(
        public ResourceResolution $resource,
        public array $references,
    ) {
    }

    public static function unavailable(ResourceResolution $resource): self
    {
        return new self($resource, []);
    }
}
