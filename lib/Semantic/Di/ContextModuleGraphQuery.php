<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

use Microsoft\PhpParser\Node\Expression\CallExpression;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/** Reads a bounded context/module graph from saved source without constructing a container. */
final readonly class ContextModuleGraphQuery
{
    private const MAX_WORKSPACE_MODULES = 300;
    private const MAX_WORKSPACE_EDGES = 1200;

    public function __construct(
        private RayModuleScanner $moduleScanner = new RayModuleScanner(),
        private ContextModuleSelector $selector = new ContextModuleSelector(),
    ) {
    }

    /** @return SemanticResult<ContextModuleGraph|null> */
    public function describeInWorkspace(
        WorkspaceContext $workspace,
        ?string $applicationContext = null,
        ?string $contextPath = null,
    ): SemanticResult {
        if (
            $applicationContext !== null && (
            $applicationContext === ''
            || preg_match('/^[A-Za-z0-9_]+(?:-[A-Za-z0-9_]+)*$/', $applicationContext) !== 1
            )
        ) {
            return SemanticResult::invalidInput();
        }
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }
        $modules = iterator_to_array($this->moduleScanner->scan($workspace, $project->value), false);
        if ($applicationContext === null) {
            $graph = $this->workspaceSourceMap($modules);
            $provenance = [Provenance::derived()];
            foreach ($graph->modules as $module) {
                $provenance[] = Provenance::savedFile($module->path);
            }

            return SemanticResult::ok(
                $graph,
                $provenance,
            );
        }
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

    /** @param list<RayModuleSource> $modules */
    private function workspaceSourceMap(array $modules): ContextModuleGraph
    {
        usort($modules, static fn (RayModuleSource $left, RayModuleSource $right): int => [
            strtolower($left->module),
            $left->path,
            $left->module,
        ] <=> [
            strtolower($right->module),
            $right->path,
            $right->module,
        ]);
        $totalModules = count($modules);
        $allModules = $modules;
        $modules = array_slice($modules, 0, self::MAX_WORKSPACE_MODULES);
        $visible = [];
        $metadata = [];
        foreach ($modules as $module) {
            $visible[strtolower($module->module)] = true;
            $metadata[$module->module] = $this->declarationCounts($module);
        }
        $edges = [];
        $totalEdges = 0;
        foreach ($this->selector->workspaceEdges($allModules) as $edge) {
            ++$totalEdges;
            if (isset($visible[strtolower($edge->source)]) && count($edges) < self::MAX_WORKSPACE_EDGES) {
                $edges[] = $edge;
            }
        }

        return new ContextModuleGraph(
            null,
            [],
            $modules,
            $edges,
            $totalModules > self::MAX_WORKSPACE_MODULES || $totalEdges > self::MAX_WORKSPACE_EDGES,
            true,
            $totalModules,
            $totalEdges,
            $metadata,
        );
    }

    /** @return array{line:int,bindingDeclarations:int,interceptorDeclarations:int} */
    private function declarationCounts(RayModuleSource $module): array
    {
        $bindings = 0;
        $interceptors = 0;
        foreach ($module->declaration->getDescendantNodes() as $node) {
            if (!$node instanceof CallExpression) {
                continue;
            }
            if (RayModuleCall::isThisMethod($node, 'bind', $module->contents)) {
                ++$bindings;
            }
            if (
                RayModuleCall::isThisMethod($node, 'bindInterceptor', $module->contents)
                || RayModuleCall::isThisMethod($node, 'bindPriorityInterceptor', $module->contents)
            ) {
                ++$interceptors;
            }
        }

        return [
            'line' => substr_count(substr($module->contents, 0, $module->declaration->getStartPosition()), "\n") + 1,
            'bindingDeclarations' => $bindings,
            'interceptorDeclarations' => $interceptors,
        ];
    }
}
