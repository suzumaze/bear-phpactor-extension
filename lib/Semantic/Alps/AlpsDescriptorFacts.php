<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Alps;

/**
 * One descriptor plus only the explicit profile relationships touching it.
 */
final readonly class AlpsDescriptorFacts
{
    /**
     * @param list<AlpsDescriptorRelationFact> $outgoingRelations
     * @param list<AlpsDescriptorRelationFact> $incomingRelations
     */
    public function __construct(
        public AlpsDescriptorFact $descriptor,
        public array $outgoingRelations,
        public array $incomingRelations,
    ) {
    }
}
