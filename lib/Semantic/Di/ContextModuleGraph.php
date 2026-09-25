<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

/** A bounded graph projection; it is not a runtime-composed Ray.Di container. */
final readonly class ContextModuleGraph
{
    /**
     * @param list<ContextModuleRoot> $segments
     * @param list<RayModuleSource> $modules
     * @param list<ContextModuleEdge> $edges
     */
    public function __construct(
        public string $applicationContext,
        public array $segments,
        public array $modules,
        public array $edges,
        public bool $truncated,
    ) {
    }
}
