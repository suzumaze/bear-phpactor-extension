<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Alps;

/**
 * Location of a top-level ALPS semantic descriptor.
 */
final readonly class AlpsDescriptorResolution
{
    public function __construct(
        public string $descriptorId,
        public string $profileFile,
        public int $offset,
    ) {
    }
}
