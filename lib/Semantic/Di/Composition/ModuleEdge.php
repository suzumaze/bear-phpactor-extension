<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di\Composition;

/** One observed source call, or an explicitly identified composition recipe step. */
final readonly class ModuleEdge
{
    public function __construct(
        public string $operation,
        public string $module,
        public string $target,
        public ?string $path = null,
        public ?int $line = null,
    ) {
    }
}
