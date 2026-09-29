<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

use Suzumaze\BearPhpactor\Semantic\Di\Composition\BearPackageComposition;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\BindingOrigin;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSourceIndex;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ModuleInterpreter;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectReportPage;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/** Explains source-derived selections, never claiming to observe a runtime container. */
final readonly class DiBindingLookupQuery
{
    private const MAX_DECISIONS = 50;

    /**
     * @param array<mixed>|null $environment
     * @return SemanticResult<array<string, mixed>|null>
     */
    public function lookupInWorkspace(
        WorkspaceContext $workspace,
        string $applicationContext,
        ?string $type = null,
        ?string $name = null,
        ?string $contextPath = null,
        int $limit = 50,
        int $offset = 0,
        bool $overridesOnly = false,
        bool $resourcesOnly = false,
        ?array $environment = null,
    ): SemanticResult {
        if (
            !DiContainerQuery::isEnvironment($environment)
            || preg_match('/^[A-Za-z0-9_]+(?:-[A-Za-z0-9_]+)*$/', $applicationContext) !== 1
            || $limit < 1 || $limit > 100 || $offset < 0
        ) {
            return SemanticResult::invalidInput();
        }
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }
        $root = $project->value->root();
        $appName = DiContainerQuery::appName($project->value->psr4(), $root);
        if ($appName === null) {
            return SemanticResult::unsupported();
        }
        $classes = new ClassSourceIndex($root, $workspace->root());
        $interpreter = new ModuleInterpreter($classes, $environment);
        $container = (new BearPackageComposition($classes, $interpreter))($appName, $applicationContext);
        $type = $type === null ? null : ltrim($type, '\\');
        $decisions = [];
        foreach ($container->events as $event) {
            // Local decisions are not necessarily the final winner. The selected field below is separate.
            if (!isset($event['lost']) && $event['type'] !== 'move') {
                continue;
            }
            if ($event['type'] === 'move' && isset($event['from'])) {
                $decisions[$event['index']] = $decisions[$event['from']] ?? [];
                unset($decisions[$event['from']]);
            }
            $decisions[$event['index']][] = [
                'operation' => $event['type'],
                'reason' => $event['reason'] ?? $event['type'],
                'retained' => isset($event['dependency'])
                    ? $this->binding($event['dependency'], $event['origin'] ?? null) : null,
                'discarded' => isset($event['lost'])
                    ? $this->binding($event['lost'], $event['lostOrigin'] ?? null) : null,
                'from' => $event['from'] ?? null,
            ];
        }
        $items = [];
        foreach ($container->bindings as $index => $dependency) {
            [$boundType, $boundName] = explode('-', $index, 2) + [1 => ''];
            if (($type !== null && $type !== $boundType) || ($name !== null && $name !== $boundName)) {
                continue;
            }
            $history = $decisions[$index] ?? [];
            $conflicts = count(array_filter($history, static fn (array $item): bool => $item['discarded'] !== null));
            if ($overridesOnly && $conflicts === 0) {
                continue;
            }
            if ($resourcesOnly && !$this->isResource($boundType, $dependency['target'], $history, $interpreter)) {
                continue;
            }
            $items[] = [
                'index' => $index,
                'type' => $boundType,
                'name' => $boundName,
                'selectionStatus' => $interpreter->unknowns === [] ? 'source_selected' : 'provisional',
                'selected' => $this->binding($dependency, $container->origins[$index] ?? null),
                'decisions' => array_slice($history, 0, self::MAX_DECISIONS),
                'decisionTotal' => count($history),
                'decisionsTruncated' => count($history) > self::MAX_DECISIONS,
            ];
        }
        usort($items, static fn (array $a, array $b): int => strcmp($a['index'], $b['index']));
        $page = ProjectReportPage::slice($items, $offset, $limit, ProjectReportPage::serializedBytes(...));

        return SemanticResult::ok([
            'applicationContext' => $applicationContext,
            'type' => $type,
            'name' => $name,
            'overridesOnly' => $overridesOnly,
            'resourcesOnly' => $resourcesOnly,
            'items' => $page,
            'total' => count($items),
            'offset' => $offset,
            'truncated' => $offset + count($page) < count($items),
            'unknowns' => array_slice($interpreter->unknowns, 0, 100),
            'unknownTotal' => count($interpreter->unknowns),
            'unknownsTruncated' => count($interpreter->unknowns) > 100,
            'coverage' => [
                'basis' => 'source-derived',
                'runtimeContainerObserved' => false,
                'environmentProfileProvided' => $environment !== null,
                'compositionRecipe' => BearPackageComposition::RECIPE_STEPS,
                'hasUnknowns' => $interpreter->unknowns !== [],
                'instanceValuesReturned' => false,
                'aopApplicationsResolved' => false,
                'resourceFilter' => 'known_resource_subclasses_in_key_or_selected_or_discarded_target',
            ],
        ], [Provenance::derived()]);
    }

    /**
     * @param array{kind: string, target: ?string} $dependency
     * @return array<string, mixed>
     */
    private function binding(array $dependency, ?BindingOrigin $origin): array
    {
        return [
            'kind' => $dependency['kind'],
            'target' => $dependency['target'],
            'origin' => $origin?->toArray(),
        ];
    }

    /** @param list<array<string, mixed>> $history */
    private function isResource(string $type, ?string $target, array $history, ModuleInterpreter $interpreter): bool
    {
        $classes = [$type, $target];
        foreach ($history as $decision) {
            $classes[] = $decision['discarded']['target'] ?? null;
        }
        foreach ($classes as $class) {
            if (is_string($class) && $interpreter->isSubclassOf($class, 'BEAR\\Resource\\ResourceObject')) {
                return true;
            }
        }

        return false;
    }
}
