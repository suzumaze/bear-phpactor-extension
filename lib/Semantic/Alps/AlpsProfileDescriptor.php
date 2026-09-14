<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Alps;

/**
 * A normalized descriptor from one bounded, workspace-local ALPS profile.
 */
final readonly class AlpsProfileDescriptor
{
    /** @param list<AlpsProfileDescriptor> $children */
    public function __construct(
        public ?string $id,
        public ?string $type,
        public ?string $name,
        public ?string $rt,
        public ?string $href,
        public ?string $rel,
        public ?string $doc,
        public ?string $def,
        public ?string $tag,
        public ?string $title,
        public ?int $offset,
        public array $children = [],
    ) {
    }
}
