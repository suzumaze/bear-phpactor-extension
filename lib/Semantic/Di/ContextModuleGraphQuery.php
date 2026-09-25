<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/** Reads a bounded context/module graph from saved source without constructing a container. */
final readonly class ContextModuleGraphQuery
{
    public function __construct(
        private RayModuleScanner $moduleScanner = new RayModuleScanner(),
        private ContextModuleSelector $selector = new ContextModuleSelector(),
    ) {
    }

    /** @return SemanticResult<ContextModuleGraph|null> */
    public function describeInWorkspace(
        WorkspaceContext $workspace,
        string $applicationContext,
        ?string $contextPath = null,
    ): SemanticResult {
        if (
            $applicationContext === ''
            || preg_match('/^[A-Za-z0-9_]+(?:-[A-Za-z0-9_]+)*$/', $applicationContext) !== 1
        ) {
            return SemanticResult::invalidInput();
        }
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }
        $modules = iterator_to_array($this->moduleScanner->scan($workspace, $project->value), false);
        $selection = $this->selector->analyze($modules, $applicationContext);
        $provenance = [Provenance::derived()];
        foreach ($selection->modules as $module) {
            $provenance[] = Provenance::savedFile($module->path);
        }

        return SemanticResult::ok(new ContextModuleGraph(
            $applicationContext,
            $selection->roots,
            $selection->modules,
            $selection->edges,
            $selection->truncated,
        ), $provenance);
    }
}
