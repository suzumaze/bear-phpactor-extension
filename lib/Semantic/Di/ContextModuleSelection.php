<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

/** Saved-source projection of workspace modules reachable from a literal context. */
final readonly class ContextModuleSelection
{
    /**
     * @param list<RayModuleSource> $modules
     * @param list<ContextModuleRoot> $roots
     * @param list<ContextModuleEdge> $edges
     */
    public function __construct(
        public array $modules,
        public array $roots,
        public array $edges,
        public bool $truncated,
    ) {
    }
}
