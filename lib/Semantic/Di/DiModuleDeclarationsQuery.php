<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

use Suzumaze\BearPhpactor\Semantic\Aop\AopPointcutFact;
use Suzumaze\BearPhpactor\Semantic\Aop\AopPointcutQuery;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/** Direct declaration view for one exact workspace Module; context membership is a source-graph overlay. */
final readonly class DiModuleDeclarationsQuery
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 100;

    public function __construct(
        private RayModuleScanner $moduleScanner = new RayModuleScanner(),
        private ContextModuleSelector $contextModuleSelector = new ContextModuleSelector(),
        private DiBindingQuery $bindingQuery = new DiBindingQuery(),
        private AopPointcutQuery $pointcutQuery = new AopPointcutQuery(),
    ) {
    }

    /** @return SemanticResult<array<string,mixed>|null> */
    public function listInWorkspace(
        WorkspaceContext $workspace,
        string $module,
        ?string $applicationContext = null,
        ?string $contextPath = null,
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0,
        ?int $bindingsOffset = null,
        ?int $pointcutsOffset = null,
    ): SemanticResult {
        $module = ltrim($module, '\\');
        // Bindings and pointcuts page independently; each list may also stop early at the
        // page byte budget, so each keeps its own offset. `offset` seeds both.
        $bindingsOffset ??= $offset;
        $pointcutsOffset ??= $offset;
        if (
            !$this->isFqcn($module)
            || ($applicationContext !== null && preg_match(
                '/^[A-Za-z0-9_]+(?:-[A-Za-z0-9_]+)*$/',
                $applicationContext,
            ) !== 1)
            || $limit < 1
            || $limit > self::MAX_LIMIT
            || $offset < 0
            || $bindingsOffset < 0
            || $pointcutsOffset < 0
        ) {
            return SemanticResult::invalidInput();
        }
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        $sources = iterator_to_array($this->moduleScanner->scan($workspace, $project->value), false);
        $sourceByClass = [];
        foreach ($sources as $source) {
            $sourceByClass[strtolower($source->module)] = $source;
        }
        $canonicalModule = $sourceByClass[strtolower($module)]->module ?? $module;
        $context = $applicationContext === null
            ? ['state' => 'not_requested', 'route' => [], 'incomingEdges' => [], 'outgoingEdges' => []]
            : $this->contextMembership(
                $canonicalModule,
                $applicationContext,
                $sources,
                $this->contextModuleSelector->analyze($sources, $applicationContext),
            );

        // The exact-module filter is applied before pagination in each declaration inventory.
        $bindings = $this->bindingQuery->listInWorkspace(
            $workspace,
            contextPath: $contextPath,
            limit: $limit,
            offset: $bindingsOffset,
            module: $canonicalModule,
        );
        if ($bindings->value === null) {
            return SemanticResult::failure($bindings->status);
        }
        $pointcuts = $this->pointcutQuery->listInWorkspace(
            $workspace,
            contextPath: $contextPath,
            limit: $limit,
            offset: $pointcutsOffset,
            module: $canonicalModule,
        );
        if ($pointcuts->value === null) {
            return SemanticResult::failure($pointcuts->status);
        }

        $provenance = [Provenance::derived()];
        $bindingItems = array_map(function ($item) use (&$provenance, $sources): array {
            $provenance[] = Provenance::savedFile($item->path, $item->byteStart, $item->byteEnd);

            return [
                'state' => $item->state,
                'module' => $item->module,
                'sourceType' => $item->sourceType,
                'targetType' => $item->targetType,
                'kind' => $item->kind,
                'qualifier' => $item->qualifier,
                'scope' => $item->scope,
                'valueType' => $item->valueType,
                'reason' => $item->reason,
                'path' => $item->path,
                'line' => $this->line($item->path, $item->byteStart, $sources),
                'byteRange' => ['start' => $item->byteStart, 'end' => $item->byteEnd],
            ];
        }, $bindings->value->items);
        $pointcutItems = array_map(function (AopPointcutFact $item) use (&$provenance, $sources): array {
            $provenance[] = Provenance::savedFile($item->path, $item->byteStart, $item->byteEnd);

            return [
                'state' => $item->state,
                'module' => $item->module,
                'classMatcher' => $item->classMatcher,
                'methodMatcher' => $item->methodMatcher,
                'interceptors' => $item->interceptors,
                'priority' => $item->priority,
                'reasons' => $item->reasons,
                'path' => $item->path,
                'line' => $this->line($item->path, $item->byteStart, $sources),
                'byteRange' => ['start' => $item->byteStart, 'end' => $item->byteEnd],
            ];
        }, $pointcuts->value->items);

        return SemanticResult::ok([
            'applicationContext' => $applicationContext,
            'module' => $canonicalModule,
            'contextMembership' => $context,
            'bindings' => [
                'items' => $bindingItems,
                'total' => $bindings->value->total,
                'offset' => $bindingsOffset,
                'truncated' => $bindings->value->truncated,
                'unresolved' => $bindings->value->unresolved,
            ],
            'pointcuts' => [
                'items' => $pointcutItems,
                'total' => $pointcuts->value->total,
                'offset' => $pointcutsOffset,
                'truncated' => $pointcuts->value->truncated,
                'unresolved' => $pointcuts->value->unresolved,
            ],
            'bindingSelection' => [
                'state' => 'not_joined',
                'request' => 'bear/di/bindingLookup',
            ],
            'coverage' => [
                'declarations' => 'direct_workspace_source',
                'contextMembership' => $applicationContext === null ? 'not_requested' : 'saved_workspace_module_graph',
                'vendorModulesExpanded' => false,
                'dynamicEdgesExpanded' => false,
                'bindingWinnerResolved' => false,
                'instanceValuesReturned' => false,
            ],
        ], $provenance);
    }

    private function isFqcn(string $module): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D', $module) === 1;
    }

    /** @param list<RayModuleSource> $sources */
    private function contextMembership(
        string $module,
        string $applicationContext,
        array $sources,
        ContextModuleSelection $selection,
    ): array {
        $sourceExists = false;
        foreach ($sources as $source) {
            if (strcasecmp($source->module, $module) === 0) {
                $sourceExists = true;
                break;
            }
        }
        $reachable = [];
        foreach ($selection->modules as $selected) {
            $reachable[strtolower($selected->module)] = true;
        }
        $relevant = array_values(array_filter(
            $selection->edges,
            static fn (ContextModuleEdge $edge): bool => strcasecmp($edge->source, $module) === 0
                || ($edge->target !== null && strcasecmp($edge->target, $module) === 0),
        ));
        $hasExternalTarget = false;
        $hasUnresolvedEdge = false;
        foreach ($selection->edges as $edge) {
            $hasExternalTarget = $hasExternalTarget || (
                $edge->target !== null
                && strcasecmp($edge->target, $module) === 0
                && $edge->state === 'external'
            );
            $hasUnresolvedEdge = $hasUnresolvedEdge || $edge->state === 'unresolved';
        }
        $state = match (true) {
            isset($reachable[strtolower($module)]) => 'present_in_workspace_graph',
            $hasExternalTarget => 'external_not_expanded',
            $hasUnresolvedEdge => 'unknown',
            $sourceExists => 'not_observed_in_workspace_graph',
            default => 'module_source_not_found',
        };

        return [
            'applicationContext' => $applicationContext,
            'state' => $state,
            'route' => $this->route($module, $selection),
            'incomingEdges' => array_values(array_map(
                fn (ContextModuleEdge $edge): array => $this->edge($edge, $sources),
                array_filter(
                    $relevant,
                    static fn (ContextModuleEdge $edge): bool => $edge->target !== null
                    && strcasecmp($edge->target, $module) === 0,
                ),
            )),
            'outgoingEdges' => array_values(array_map(
                fn (ContextModuleEdge $edge): array => $this->edge($edge, $sources),
                array_filter(
                    $relevant,
                    static fn (ContextModuleEdge $edge): bool => strcasecmp($edge->source, $module) === 0,
                ),
            )),
            'graphTruncated' => $selection->truncated,
            'unresolvedEdgeTotal' => count(array_filter(
                $selection->edges,
                static fn (ContextModuleEdge $edge): bool => $edge->state === 'unresolved',
            )),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function route(string $module, ContextModuleSelection $selection): array
    {
        $starts = array_values(array_filter(array_map(
            static fn (ContextModuleRoot $root): ?string => $root->selected,
            $selection->roots,
        )));
        $queue = [];
        foreach ($starts as $start) {
            $queue[] = [$start, []];
        }
        $seen = [];
        while ($queue !== []) {
            [$current, $path] = array_shift($queue);
            if (strcasecmp($current, $module) === 0) {
                return array_map(fn (ContextModuleEdge $edge): array => $this->edge($edge, $selection->modules), $path);
            }
            if (isset($seen[strtolower($current)])) {
                continue;
            }
            $seen[strtolower($current)] = true;
            foreach ($selection->edges as $edge) {
                if (
                    strcasecmp($edge->source, $current) === 0
                    && $edge->state === 'workspace'
                    && $edge->target !== null
                ) {
                    $queue[] = [$edge->target, [...$path, $edge]];
                }
            }
        }

        return [];
    }

    /** @param list<RayModuleSource> $sources
     *  @return array<string,mixed>
     */
    private function edge(ContextModuleEdge $edge, array $sources): array
    {
        return [
            'source' => $edge->source,
            'target' => $edge->target,
            'kind' => $edge->kind,
            'state' => $edge->state,
            'reason' => $edge->reason,
            'path' => $edge->path,
            'line' => $this->line($edge->path, $edge->byteStart, $sources),
            'byteRange' => ['start' => $edge->byteStart, 'end' => $edge->byteEnd],
        ];
    }

    /** @param list<RayModuleSource> $sources */
    private function line(string $path, int $byteStart, array $sources): ?int
    {
        foreach ($sources as $source) {
            if ($source->path === $path) {
                return substr_count(substr($source->contents, 0, $byteStart), "\n") + 1;
            }
        }

        return null;
    }
}
