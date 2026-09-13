<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Alps;

/**
 * Transport-independent source range of one Alps attribute reference.
 */
final readonly class AlpsDescriptorReference
{
    public function __construct(
        public string $descriptorId,
        public string $sourceFile,
        public int $contentStart,
        public int $contentEnd,
    ) {
    }
}
