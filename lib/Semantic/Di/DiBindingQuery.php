<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Expression\AssignmentExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectReportPage;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/**
 * Inventories saved Ray.Di declarations without executing modules or resolving a container.
 */
final readonly class DiBindingQuery
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 100;

    /** Ray.Di 2.x `Bind` chain methods: parameter names and required argument count. */
    private const OPERATIONS = [
        'annotatedWith' => [['name'], 1],
        'to' => [['class'], 1],
        'toProvider' => [['provider', 'context'], 1],
        'toConstructor' => [['class', 'name', 'injectionPoints', 'postConstruct'], 2],
        'toInstance' => [['instance'], 1],
        'toNull' => [[], 0],
        'in' => [['scope'], 1],
    ];

    private const TARGET_KINDS = [
        'to' => 'class',
        'toProvider' => 'provider',
        'toInstance' => 'instance',
        'toConstructor' => 'constructor',
        'toNull' => 'null',
    ];

    private const NO_TARGET = [
        'targetType' => null,
        'valueType' => null,
    ];

    /** `Ray\Di\Scope` values, compared exactly as `Dependency::setScope()` does. */
    private const SCOPES = [
        'Singleton' => 'singleton',
        'Prototype' => 'prototype',
    ];

    public function __construct(
        private RayModuleScanner $moduleScanner = new RayModuleScanner(),
        private ContextModuleSelector $contextModuleSelector = new ContextModuleSelector(),
    ) {
    }

    /** @return SemanticResult<DiBindingInventory|null> */
    public function listInWorkspace(
        WorkspaceContext $workspace,
        ?string $type = null,
        ?string $contextPath = null,
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0,
        ?string $applicationContext = null,
        ?string $module = null,
    ): SemanticResult {
        if (
            $limit < 1
            || $limit > self::MAX_LIMIT
            || $offset < 0
            || $type === ''
            || $applicationContext === ''
            || $module === ''
        ) {
            return SemanticResult::invalidInput();
        }
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }
        $type = $type === null ? null : ltrim($type, '\\');
        $module = $module === null ? null : ltrim($module, '\\');
        $items = [];
        $modules = 0;
        $moduleSources = iterator_to_array($this->moduleScanner->scan($workspace, $project->value), false);
        if ($applicationContext !== null) {
            $moduleSources = $this->contextModuleSelector->select($moduleSources, $applicationContext);
        }
        foreach ($moduleSources as $moduleSource) {
            ++$modules;
            if ($module !== null && strcasecmp($moduleSource->module, $module) !== 0) {
                continue;
            }
            foreach ($moduleSource->declaration->getDescendantNodes() as $node) {
                if (
                    !$node instanceof CallExpression
                    || !RayModuleCall::isThisMethod($node, 'bind', $moduleSource->contents)
                ) {
                    continue;
                }
                $fact = $this->binding($moduleSource, $node);
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
        $source = $module->contents;
        $bindArguments = $this->signatureArguments($bind, ['interface'], 0, $source);
        $sourceType = match (true) {
            $bindArguments === null => null,
            !isset($bindArguments['interface']) => '',
            default => RayModuleCall::staticName($bindArguments['interface'], $source),
        };
        $reason = $sourceType === null ? 'binding_source_not_static' : null;
        $fact = ['kind' => 'untargeted', 'qualifier' => null, 'scope' => null, ...self::NO_TARGET];
        $seen = [];
        for ($call = RayModuleCall::chainedCall($bind); $call !== null; $call = RayModuleCall::chainedCall($call)) {
            $operation = RayModuleCall::methodName($call, $source) ?? '';
            if (!isset(self::OPERATIONS[$operation])) {
                $reason ??= 'binding_chain_unsupported';
                continue;
            }
            $targeted = $fact['kind'] !== 'untargeted';
            $isTarget = isset(self::TARGET_KINDS[$operation]);
            // Container::add() keys the binding when a target method runs, and target methods
            // drop the untargeted binding that an earlier in() configured.
            $reason ??= match (true) {
                $targeted && $isTarget => 'binding_multiple_targets',
                isset($seen[$operation]) => 'binding_operation_repeated',
                $targeted && $operation === 'annotatedWith' => 'binding_qualifier_after_target',
                $isTarget && isset($seen['in']) => 'binding_scope_before_target',
                default => null,
            };
            $seen[$operation] = true;
            [$parameters, $required] = self::OPERATIONS[$operation];
            $arguments = $this->signatureArguments($call, $parameters, $required, $source);
            if ($arguments === null) {
                $reason ??= 'binding_arguments_unsupported';
            }
            if ($isTarget) {
                $fact = [...$fact, ...self::NO_TARGET, 'kind' => self::TARGET_KINDS[$operation]];
            }
            [$update, $operationReason] = $this->operation($operation, $arguments ?? [], $source);
            $fact = [...$fact, ...$update];
            $reason ??= $operationReason;
        }
        if ($fact['kind'] === 'untargeted' && $sourceType === '') {
            $reason ??= 'binding_untargeted_type_missing';
        }
        // A Bind stored in a variable or property can receive annotatedWith(), to() or in()
        // in later statements, which this chain reader does not follow.
        if (RayModuleCall::terminalCall($bind)->parent instanceof AssignmentExpression) {
            $reason ??= 'binding_chain_retained';
        }

        return new DiBindingFact(
            $reason === null ? DiBindingFact::STATE_RESOLVED : DiBindingFact::STATE_UNRESOLVED,
            $module->module,
            $sourceType,
            $fact['targetType'],
            $reason,
            $module->path,
            $bind->getStartPosition(),
            RayModuleCall::terminalCall($bind)->getEndPosition(),
            $fact['kind'],
            $fact['qualifier'],
            $fact['scope'],
            $fact['valueType'],
        );
    }

    /**
     * @param array<string,Node> $arguments
     * @return array{array<string,string|null>,string|null} fact fields to overwrite, and a reason
     */
    private function operation(string $operation, array $arguments, string $source): array
    {
        return match ($operation) {
            'annotatedWith' => $this->qualifier($arguments['name'] ?? null, $source),
            'in' => $this->scope($arguments['scope'] ?? null, $source),
            'toInstance' => [[
                'valueType' => StaticBindingValue::valueType($arguments['instance'] ?? null, $source),
            ], null],
            'toNull' => [[], null],
            default => $this->classTarget($operation, $arguments, $source),
        };
    }

    /** @return array{array<string,string|null>,string|null} */
    private function qualifier(?Node $name, string $source): array
    {
        $qualifier = RayModuleCall::staticName($name, $source);

        return [['qualifier' => $qualifier], $qualifier === null ? 'binding_qualifier_not_static' : null];
    }

    /** @return array{array<string,string|null>,string|null} */
    private function scope(?Node $expression, string $source): array
    {
        $declared = StaticBindingValue::scope($expression, $source);
        $scope = $declared === null ? null : self::SCOPES[$declared] ?? null;

        return [['scope' => $scope], match (true) {
            $declared === null => 'binding_scope_not_static',
            $scope === null => 'binding_scope_unknown',
            default => null,
        }];
    }

    /**
     * Reads `to()`, `toProvider()`, and `toConstructor()` targets.
     *
     * @param array<string,Node> $arguments
     * @return array{array<string,string|null>,string|null}
     */
    private function classTarget(string $operation, array $arguments, string $source): array
    {
        $class = $arguments[$operation === 'toProvider' ? 'provider' : 'class'] ?? null;
        $targetType = RayModuleCall::staticName($class, $source);
        $fact = ['targetType' => $targetType === '' ? null : $targetType];
        $reason = $targetType === null || $targetType === '' ? 'binding_target_not_static' : null;
        if ($operation === 'toProvider') {
            $context = $arguments['context'] ?? null;
            if ($context !== null && RayModuleCall::staticName($context, $source) === null) {
                $reason ??= 'binding_provider_context_not_static';
            }
        }
        if ($operation !== 'toConstructor') {
            return [$fact, $reason];
        }
        $injectionPoints = $arguments['injectionPoints'] ?? null;
        $postConstruct = $arguments['postConstruct'] ?? null;
        $reason ??= match (true) {
            !StaticBindingValue::isNameMapping($arguments['name'] ?? null, $source)
                => 'binding_constructor_arguments_not_static',
            $injectionPoints !== null && !StaticBindingValue::isNull($injectionPoints, $source)
                => 'binding_constructor_injection_points_not_static',
            $postConstruct !== null
                && !StaticBindingValue::isNull($postConstruct, $source)
                && RayModuleCall::staticName($postConstruct, $source) === null
                => 'binding_constructor_post_construct_not_static',
            default => null,
        };

        return [$fact, $reason];
    }

    /**
     * Maps positional and named arguments onto a Ray.Di method's parameter names.
     *
     * @param list<string> $parameters
     * @return array<string,Node>|null null for spread, unknown, duplicated, or missing required arguments
     */
    private function signatureArguments(CallExpression $call, array $parameters, int $required, string $source): ?array
    {
        $arguments = RayModuleCall::arguments($call);
        if ($arguments === null || count($arguments) > count($parameters)) {
            return null;
        }
        $mapped = [];
        foreach ($arguments as $position => $argument) {
            $name = $argument->name?->getText($source) ?? $parameters[$position];
            if (
                !in_array($name, $parameters, true)
                || isset($mapped[$name])
                || !$argument->expression instanceof Node
            ) {
                return null;
            }
            $mapped[$name] = $argument->expression;
        }
        foreach (array_slice($parameters, 0, $required) as $name) {
            if (!isset($mapped[$name])) {
                return null;
            }
        }

        return $mapped;
    }
}
