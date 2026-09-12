<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Suzumaze\BearPhpactor\Semantic\Schema\SchemaResolution;
use Suzumaze\BearPhpactor\Semantic\Template\ResourceTemplateResolution;

/**
 * Resource facts combined with workspace-derived relationships.
 */
final readonly class ResourceDescription
{
    /**
     * @param list<ResourceTemplateResolution> $templates
     */
    public function __construct(
        public ResourceFacts $facts,
        public ResourceIncomingRelations $incomingRelations,
        public array $templates = [],
        public ?SchemaResolution $responseSchema = null,
    ) {
    }
}
