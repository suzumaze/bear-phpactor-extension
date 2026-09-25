<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\ObjectCreationExpression;
use Microsoft\PhpParser\Node\QualifiedName;

/**
 * Selects workspace modules reachable from a BEAR application context.
 *
 * This is a saved-source projection. Vendor-only context segments and dynamic
 * install arguments remain outside this selector rather than being guessed.
 */
final class ContextModuleSelector
{
    private const MAX_MODULES = 300;

    /**
     * @param list<RayModuleSource> $modules
     * @return list<RayModuleSource>
     */
    public function select(array $modules, string $context): array
    {
        return $this->analyze($modules, $context)->modules;
    }

    /**
     * @param list<RayModuleSource> $modules
     */
    public function analyze(array $modules, string $context): ContextModuleSelection
    {
        if ($context === '' || preg_match('/^[A-Za-z0-9_]+(?:-[A-Za-z0-9_]+)*$/', $context) !== 1) {
            return new ContextModuleSelection([], [], [], false);
        }
        /** @var array<string, RayModuleSource> $byClass */
        $byClass = [];
        $appNamespace = null;
        foreach ($modules as $module) {
            $byClass[$module->module] = $module;
            if ($module->path === 'src/Module/AppModule.php' && str_ends_with($module->module, '\\Module\\AppModule')) {
                $appNamespace = substr($module->module, 0, -strlen('\\Module\\AppModule'));
            }
        }
        if ($appNamespace === null) {
            return new ContextModuleSelection([], [], [], false);
        }

        $queue = [];
        $roots = [];
        foreach (explode('-', $context) as $priority => $segment) {
            $class = $appNamespace . '\\Module\\' . ucfirst($segment) . 'Module';
            $framework = 'BEAR\\Package\\Context\\' . ucfirst($segment) . 'Module';
            $selected = isset($byClass[$class]) ? $class : null;
            $roots[] = new ContextModuleRoot(
                $segment,
                $priority + 1,
                [$class, $framework],
                $selected,
                $selected === null ? 'external_or_unresolved' : 'workspace',
            );
            if (isset($byClass[$class])) {
                $queue[] = $class;
            }
        }

        $selected = [];
        $edges = [];
        $truncated = false;
        while ($queue !== []) {
            $class = array_shift($queue);
            if (isset($selected[$class]) || !isset($byClass[$class])) {
                continue;
            }
            if (count($selected) >= self::MAX_MODULES) {
                $truncated = true;
                break;
            }
            $module = $byClass[$class];
            $selected[$class] = $module;
            $parentState = isset($byClass[$module->parent]) ? 'workspace' : 'external';
            $edges[] = new ContextModuleEdge(
                $module->module,
                $module->parent,
                'extends',
                $parentState,
                null,
                $module->path,
                $module->declaration->getStartPosition(),
                $module->declaration->getEndPosition(),
            );
            if ($parentState === 'workspace') {
                $queue[] = $module->parent;
            }
            foreach ($this->moduleEdges($module, $byClass) as $edge) {
                $edges[] = $edge;
                if ($edge->target !== null && $edge->state === 'workspace') {
                    $queue[] = $edge->target;
                }
            }
        }

        return new ContextModuleSelection(array_values($selected), $roots, $edges, $truncated);
    }

    /**
     * @param array<string, RayModuleSource> $byClass
     * @return list<ContextModuleEdge>
     */
    private function moduleEdges(RayModuleSource $module, array $byClass): array
    {
        $edges = [];
        foreach ($module->declaration->getDescendantNodes() as $node) {
            if (!$node instanceof CallExpression) {
                continue;
            }
            $kind = RayModuleCall::isThisMethod($node, 'install', $module->contents)
                ? 'install'
                : (RayModuleCall::isThisMethod($node, 'override', $module->contents) ? 'override' : null);
            if ($kind === null) {
                continue;
            }
            $arguments = RayModuleCall::arguments($node);
            $new = $arguments !== null && count($arguments) === 1 ? $arguments[0]->expression : null;
            if (!$new instanceof ObjectCreationExpression || !$new->classTypeDesignator instanceof QualifiedName) {
                $edges[] = new ContextModuleEdge(
                    $module->module,
                    null,
                    $kind,
                    'unresolved',
                    'module_expression_not_static',
                    $module->path,
                    $node->getStartPosition(),
                    $node->getEndPosition(),
                );
                continue;
            }
            $resolved = $new->classTypeDesignator->getResolvedName();
            $target = $resolved === null ? null : ltrim((string) $resolved, '\\');
            $edges[] = new ContextModuleEdge(
                $module->module,
                $target,
                $kind,
                $target === null ? 'unresolved' : (isset($byClass[$target]) ? 'workspace' : 'external'),
                $target === null ? 'module_class_unresolved' : null,
                $module->path,
                $node->getStartPosition(),
                $node->getEndPosition(),
            );
        }

        return $edges;
    }
}
