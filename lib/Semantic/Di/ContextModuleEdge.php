<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

/** One saved-source inheritance or module-composition edge. */
final readonly class ContextModuleEdge
{
    public function __construct(
        public string $source,
        public ?string $target,
        public string $kind,
        public string $state,
        public ?string $reason,
        public string $path,
        public int $byteStart,
        public int $byteEnd,
    ) {
    }
}
