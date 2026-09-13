<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Alps;

/**
 * A resolved descriptor identity and its explicit PHP attribute usages.
 */
final readonly class AlpsDescriptorReferences
{
    /** @param list<AlpsDescriptorReference> $references */
    public function __construct(
        public AlpsDescriptorResolution $descriptor,
        public array $references,
    ) {
    }
}
