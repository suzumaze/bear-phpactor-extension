<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

use Microsoft\PhpParser\Node\Expression\CallExpression;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectReportPage;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/**
 * Inventories only direct `$this->bind(X)->to(Y)` declarations.
 *
 * Qualifiers, providers, instances, overrides, multibindings, and module
 * composition remain explicit unresolved facts rather than guessed bindings.
 */
final readonly class DiBindingQuery
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 100;

    public function __construct(
        private RayModuleScanner $moduleScanner = new RayModuleScanner(),
    ) {
    }

    /** @return SemanticResult<DiBindingInventory|null> */
    public function listInWorkspace(
        WorkspaceContext $workspace,
        ?string $type = null,
        ?string $contextPath = null,
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0,
    ): SemanticResult {
        if ($limit < 1 || $limit > self::MAX_LIMIT || $offset < 0 || $type === '') {
            return SemanticResult::invalidInput();
        }
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }
        $type = $type === null ? null : ltrim($type, '\\');
        $items = [];
        $modules = 0;
        foreach ($this->moduleScanner->scan($workspace, $project->value) as $module) {
            ++$modules;
            foreach ($module->declaration->getDescendantNodes() as $node) {
                if (
                    !$node instanceof CallExpression
                    || !RayModuleCall::isThisMethod($node, 'bind', $module->contents)
                ) {
                    continue;
                }
                $fact = $this->binding($module, $node);
                if ($type !== null && $fact->sourceType !== $type) {
                    continue;
                }
                $items[] = $fact;
            }
        }
        usort($items, static fn (DiBindingFact $left, DiBindingFact $right): int => [
            $left->path,
            $left->byteStart,
            $left->module,
        ] <=> [
            $right->path,
            $right->byteStart,
            $right->module,
        ]);
        $total = count($items);
        $unresolved = count(array_filter(
            $items,
            static fn (DiBindingFact $item): bool => $item->state === DiBindingFact::STATE_UNRESOLVED,
        ));
        $selected = ProjectReportPage::slice(
            $items,
            $offset,
            $limit,
            static fn (DiBindingFact $item): int => ProjectReportPage::serializedBytes($item),
        );
        $provenance = [Provenance::derived()];
        foreach ($selected as $item) {
            $provenance[] = Provenance::savedFile($item->path, $item->byteStart, $item->byteEnd);
        }

        return SemanticResult::ok(
            new DiBindingInventory(
                $selected,
                $total,
                $offset,
                $offset + count($selected) < $total,
                $modules,
                $unresolved,
                $type,
            ),
            $provenance,
        );
    }

    private function binding(RayModuleSource $module, CallExpression $bind): DiBindingFact
    {
        $terminal = RayModuleCall::terminalCall($bind);
        $source = $this->singleStaticArgument($bind, $module->contents);
        $next = RayModuleCall::chainedCall($bind);
        $operation = $next === null ? null : RayModuleCall::methodName($next, $module->contents);
        $target = $operation === 'to'
            ? $this->singleStaticArgument($next, $module->contents)
            : null;
        $reason = match (true) {
            $source === null => 'binding_source_not_static',
            $next === null => 'binding_target_missing',
            $operation !== 'to' => 'binding_chain_unsupported',
            RayModuleCall::chainedCall($next) !== null => 'binding_chain_unsupported',
            $target === null => 'binding_target_not_static',
            default => null,
        };

        return new DiBindingFact(
            $reason === null ? DiBindingFact::STATE_RESOLVED : DiBindingFact::STATE_UNRESOLVED,
            $module->module,
            $source,
            $target,
            $reason,
            $module->path,
            $bind->getStartPosition(),
            $terminal->getEndPosition(),
        );
    }

    private function singleStaticArgument(CallExpression $call, string $source): ?string
    {
        $arguments = RayModuleCall::arguments($call);
        if ($arguments === null || count($arguments) !== 1 || $arguments[0]->name !== null) {
            return null;
        }

        return RayModuleCall::staticName($arguments[0]->expression, $source);
    }
}
