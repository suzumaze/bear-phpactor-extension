<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Aop;

use Microsoft\PhpParser\Node\ArrayElement;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\Expression\ArrayCreationExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\Expression\Variable;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Token;
use Suzumaze\BearPhpactor\Semantic\Di\RayModuleCall;
use Suzumaze\BearPhpactor\Semantic\Di\RayModuleScanner;
use Suzumaze\BearPhpactor\Semantic\Di\RayModuleSource;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectReportPage;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/**
 * Inventories the closed Ray.Aop matcher vocabulary without evaluating it.
 */
final readonly class AopPointcutQuery
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 100;

    public function __construct(
        private RayModuleScanner $moduleScanner = new RayModuleScanner(),
    ) {
    }

    /** @return SemanticResult<AopPointcutInventory|null> */
    public function listInWorkspace(
        WorkspaceContext $workspace,
        ?string $interceptor = null,
        ?string $contextPath = null,
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0,
    ): SemanticResult {
        if ($limit < 1 || $limit > self::MAX_LIMIT || $offset < 0 || $interceptor === '') {
            return SemanticResult::invalidInput();
        }
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }
        $interceptor = $interceptor === null ? null : ltrim($interceptor, '\\');
        $items = [];
        $modules = 0;
        foreach ($this->moduleScanner->scan($workspace, $project->value) as $module) {
            ++$modules;
            foreach ($module->declaration->getDescendantNodes() as $node) {
                if (!$node instanceof CallExpression) {
                    continue;
                }
                $priority = RayModuleCall::isThisMethod($node, 'bindPriorityInterceptor', $module->contents);
                if (!$priority && !RayModuleCall::isThisMethod($node, 'bindInterceptor', $module->contents)) {
                    continue;
                }
                $fact = $this->pointcut($module, $node, $priority);
                if ($interceptor !== null && !in_array($interceptor, $fact->interceptors, true)) {
                    continue;
                }
                $items[] = $fact;
            }
        }
        usort($items, static fn (AopPointcutFact $left, AopPointcutFact $right): int => [
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
            static fn (AopPointcutFact $item): bool => $item->state === AopPointcutFact::STATE_UNRESOLVED,
        ));
        $selected = ProjectReportPage::slice(
            $items,
            $offset,
            $limit,
            static fn (AopPointcutFact $item): int => ProjectReportPage::serializedBytes($item),
        );
        $provenance = [Provenance::derived()];
        foreach ($selected as $item) {
            $provenance[] = Provenance::savedFile($item->path, $item->byteStart, $item->byteEnd);
        }

        return SemanticResult::ok(
            new AopPointcutInventory(
                $selected,
                $total,
                $offset,
                $offset + count($selected) < $total,
                $modules,
                $unresolved,
                $interceptor,
            ),
            $provenance,
        );
    }

    private function pointcut(
        RayModuleSource $module,
        CallExpression $call,
        bool $priority,
    ): AopPointcutFact {
        $arguments = $this->orderedArguments($call, $module->contents);
        $reasons = [];
        $classMatcher = null;
        $methodMatcher = null;
        $interceptors = [];
        if ($arguments === null) {
            $reasons[] = 'binding_arguments_unreadable';
        } else {
            $classMatcher = $this->matcher($arguments[0]->expression, $module->contents);
            $methodMatcher = $this->matcher($arguments[1]->expression, $module->contents);
            if ($classMatcher === null) {
                $reasons[] = 'class_matcher_unreadable';
            }
            if ($methodMatcher === null) {
                $reasons[] = 'method_matcher_unreadable';
            }
            [$interceptors, $complete] = $this->interceptors($arguments[2]->expression, $module->contents);
            if (!$complete) {
                $reasons[] = 'interceptors_unreadable';
            }
        }

        return new AopPointcutFact(
            $reasons === [] ? AopPointcutFact::STATE_RESOLVED : AopPointcutFact::STATE_UNRESOLVED,
            $module->module,
            $classMatcher,
            $methodMatcher,
            $interceptors,
            $priority,
            $reasons,
            $module->path,
            $call->getStartPosition(),
            $call->getEndPosition(),
        );
    }

    /** @return array{ArgumentExpression,ArgumentExpression,ArgumentExpression}|null */
    private function orderedArguments(CallExpression $call, string $source): ?array
    {
        $arguments = RayModuleCall::arguments($call);
        if ($arguments === null || count($arguments) !== 3) {
            return null;
        }
        $parameterNames = ['classMatcher', 'methodMatcher', 'interceptors'];
        $ordered = [null, null, null];
        foreach ($arguments as $position => $argument) {
            $name = $argument->name?->getText($source);
            $slot = $name === null ? $position : array_search($name, $parameterNames, true);
            if (!is_int($slot) || $ordered[$slot] !== null) {
                return null;
            }
            $ordered[$slot] = $argument;
        }

        return $ordered[0] !== null && $ordered[1] !== null && $ordered[2] !== null
            ? [$ordered[0], $ordered[1], $ordered[2]]
            : null;
    }

    /** @return array<string,mixed>|null */
    private function matcher(mixed $expression, string $source): ?array
    {
        if (!$expression instanceof CallExpression || !$this->isMatcherCall($expression, $source)) {
            return null;
        }
        $name = RayModuleCall::methodName($expression, $source);
        $arguments = RayModuleCall::arguments($expression);
        $namedArguments = $arguments === null ? [] : array_filter(
            $arguments,
            static fn (ArgumentExpression $argument): bool => $argument->name !== null,
        );
        if ($name === null || $arguments === null || $namedArguments !== []) {
            return null;
        }

        return match ($name) {
            'any' => $arguments === [] ? ['kind' => 'any'] : null,
            'annotatedWith' => $this->nameMatcher('annotated_with', $arguments, $source),
            'subclassesOf' => $this->nameMatcher('subclasses_of', $arguments, $source),
            'startsWith' => $this->startsWithMatcher($arguments),
            'logicalAnd' => $this->logicalMatcher('logical_and', $arguments, $source),
            'logicalOr' => $this->logicalMatcher('logical_or', $arguments, $source),
            'logicalNot' => $this->logicalNotMatcher($arguments, $source),
            default => null,
        };
    }

    private function isMatcherCall(CallExpression $call, string $source): bool
    {
        $method = $call->callableExpression;
        if (!$method instanceof MemberAccessExpression) {
            return false;
        }
        $matcher = $method->dereferencableExpression;

        return $matcher instanceof MemberAccessExpression
            && $matcher->memberName->getText($source) === 'matcher'
            && $matcher->dereferencableExpression instanceof Variable
            && $matcher->dereferencableExpression->getName() === 'this';
    }

    /** @param list<ArgumentExpression> $arguments @return array<string,mixed>|null */
    private function nameMatcher(string $kind, array $arguments, string $source): ?array
    {
        if (count($arguments) !== 1) {
            return null;
        }
        $name = RayModuleCall::staticName($arguments[0]->expression, $source);

        return $name === null ? null : ['kind' => $kind, 'value' => $name];
    }

    /** @param list<ArgumentExpression> $arguments @return array<string,mixed>|null */
    private function startsWithMatcher(array $arguments): ?array
    {
        if (count($arguments) !== 1 || !$arguments[0]->expression instanceof StringLiteral) {
            return null;
        }

        return ['kind' => 'starts_with', 'value' => $arguments[0]->expression->getStringContentsText()];
    }

    /** @param list<ArgumentExpression> $arguments @return array<string,mixed>|null */
    private function logicalMatcher(string $kind, array $arguments, string $source): ?array
    {
        if (count($arguments) < 2) {
            return null;
        }
        $operands = [];
        foreach ($arguments as $argument) {
            $operand = $this->matcher($argument->expression, $source);
            if ($operand === null) {
                return null;
            }
            $operands[] = $operand;
        }

        return ['kind' => $kind, 'operands' => $operands];
    }

    /** @param list<ArgumentExpression> $arguments @return array<string,mixed>|null */
    private function logicalNotMatcher(array $arguments, string $source): ?array
    {
        if (count($arguments) !== 1) {
            return null;
        }
        $operand = $this->matcher($arguments[0]->expression, $source);

        return $operand === null ? null : ['kind' => 'logical_not', 'operand' => $operand];
    }

    /** @return array{list<string>,bool} */
    private function interceptors(mixed $expression, string $source): array
    {
        if (!$expression instanceof ArrayCreationExpression || $expression->arrayElements === null) {
            return [[], false];
        }
        $interceptors = [];
        $complete = true;
        foreach ($expression->arrayElements->getElements() as $element) {
            if (
                !$element instanceof ArrayElement
                || $element->elementKey !== null
                || $element->dotDotDot instanceof Token
            ) {
                $complete = false;
                continue;
            }
            $name = RayModuleCall::staticName($element->elementValue, $source);
            if ($name === null) {
                $complete = false;
                continue;
            }
            $interceptors[] = $name;
        }

        return [array_values(array_unique($interceptors)), $complete];
    }
}
