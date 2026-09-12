<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

/**
 * Resource facts combined with workspace-derived relationships.
 */
final readonly class ResourceDescription
{
    public function __construct(
        public ResourceFacts $facts,
        public ResourceIncomingRelations $incomingRelations,
    ) {
    }
}
