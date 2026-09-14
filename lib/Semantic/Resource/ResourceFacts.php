<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

final readonly class ResourceFacts
{
    /**
     * @param list<ResourceMethodFact>   $methods
     * @param list<ResourceRelationFact> $outgoingRelations
     */
    public function __construct(
        public ResourceResolution $resource,
        public array $methods,
        public array $outgoingRelations,
    ) {
    }
}
