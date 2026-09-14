<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Alps;

use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;

/**
 * An explicit relationship between addressable descriptors in one profile.
 */
final readonly class AlpsDescriptorRelationFact
{
    public const KIND_CONTAINS = 'contains';
    public const KIND_HREF = 'href';
    public const KIND_RT = 'rt';

    public function __construct(
        public string $kind,
        public ?string $sourceId,
        public string $targetId,
        public SemanticStatus $targetStatus,
        public ?int $sourceOffset,
        public ?int $targetOffset,
    ) {
    }
}
