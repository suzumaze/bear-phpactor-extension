<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

final readonly class ResourceFacts
{
    /**
     * @param list<ResourceMethodFact>   $methods
     * @param list<ResourceRelationFact> $outgoingRelations
     * @param list<ResourceAttributeFact> $attributes
     * @param list<ResourceCallFact>     $outgoingReferences
     */
    public function __construct(
        public ResourceResolution $resource,
        public array $methods,
        public array $outgoingRelations,
        public array $attributes = [],
        public array $outgoingReferences = [],
        /** `@property ... $body` in the class's own docblock; null when it declares none */
        public ?ResourceDeclaredBody $declaredBody = null,
        /**
         * Link/Embed attributes left out of $outgoingRelations because their target could not be read
         *
         * @var list<ResourceUnresolvedRelationFact>
         */
        public array $unresolvedRelations = [],
    ) {
    }
}
