<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

/** One BEAR context segment and the module candidates implied by its naming convention. */
final readonly class ContextModuleRoot
{
    /** @param list<string> $candidates */
    public function __construct(
        public string $segment,
        public int $priority,
        public array $candidates,
        public ?string $selected,
        public string $state,
    ) {
    }
}
