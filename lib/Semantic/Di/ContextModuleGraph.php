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
     * @param array<string, array{line:int,bindingDeclarations:int,interceptorDeclarations:int}> $moduleMetadata
     */
    public function __construct(
        public ?string $applicationContext,
        public array $segments,
        public array $modules,
        public array $edges,
        public bool $truncated,
        public bool $workspaceSourceMap = false,
        public int $totalModules = 0,
        public int $totalEdges = 0,
        public array $moduleMetadata = [],
    ) {
    }
}
