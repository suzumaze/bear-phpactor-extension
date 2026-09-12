<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Alps;

/**
 * Statically knowable fields of one addressable ALPS descriptor.
 */
final readonly class AlpsDescriptorFact
{
    public function __construct(
        public AlpsDescriptorResolution $resolution,
        public ?string $type,
        public ?string $name,
        public ?string $rt,
        public ?string $href,
        public ?string $rel,
        public ?string $doc,
        public ?string $def,
        public ?string $tag,
        public ?string $title,
    ) {
    }
}
