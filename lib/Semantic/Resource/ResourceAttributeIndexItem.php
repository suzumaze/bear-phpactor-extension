<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;

final readonly class ResourceAttributeIndexItem
{
    /** @param list<ResourceAttributeFact> $attributes */
    public function __construct(
        public ResourceResolution $resource,
        public SemanticStatus $status,
        public array $attributes,
    ) {
    }
}
