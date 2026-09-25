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
    /**
     * @param list<RayModuleSource> $modules
     * @return list<RayModuleSource>
     */
    public function select(array $modules, string $context): array
    {
        if ($context === '' || preg_match('/^[A-Za-z0-9_]+(?:-[A-Za-z0-9_]+)*$/', $context) !== 1) {
            return [];
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
            return [];
        }

        $queue = [];
        foreach (explode('-', $context) as $segment) {
            $class = $appNamespace . '\\Module\\' . ucfirst($segment) . 'Module';
            if (isset($byClass[$class])) {
                $queue[] = $class;
            }
        }

        $selected = [];
        while ($queue !== []) {
            $class = array_shift($queue);
            if (isset($selected[$class]) || !isset($byClass[$class])) {
                continue;
            }
            $module = $byClass[$class];
            $selected[$class] = $module;
            if (isset($byClass[$module->parent])) {
                $queue[] = $module->parent;
            }
            foreach ($this->installedModules($module) as $installed) {
                if (isset($byClass[$installed])) {
                    $queue[] = $installed;
                }
            }
        }

        return array_values(array_filter(
            $modules,
            static fn (RayModuleSource $module): bool => isset($selected[$module->module]),
        ));
    }

    /** @return list<string> */
    private function installedModules(RayModuleSource $module): array
    {
        $installed = [];
        foreach ($module->declaration->getDescendantNodes() as $node) {
            if (
                !$node instanceof CallExpression
                || (!RayModuleCall::isThisMethod($node, 'install', $module->contents)
                    && !RayModuleCall::isThisMethod($node, 'override', $module->contents))
            ) {
                continue;
            }
            $arguments = RayModuleCall::arguments($node);
            $new = $arguments !== null && count($arguments) === 1 ? $arguments[0]->expression : null;
            if (!$new instanceof ObjectCreationExpression || !$new->classTypeDesignator instanceof QualifiedName) {
                continue;
            }
            $resolved = $new->classTypeDesignator->getResolvedName();
            if ($resolved !== null) {
                $installed[] = ltrim((string) $resolved, '\\');
            }
        }

        return array_values(array_unique($installed));
    }
}
