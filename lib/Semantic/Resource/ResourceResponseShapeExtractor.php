<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\ArrayElement;
use Microsoft\PhpParser\Node\ElseClauseNode;
use Microsoft\PhpParser\Node\ElseIfClauseNode;
use Microsoft\PhpParser\Node\Expression\AnonymousFunctionCreationExpression;
use Microsoft\PhpParser\Node\Expression\ArrowFunctionCreationExpression;
use Microsoft\PhpParser\Node\Expression\ArrayCreationExpression;
use Microsoft\PhpParser\Node\Expression\AssignmentExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\Expression\ObjectCreationExpression;
use Microsoft\PhpParser\Node\Expression\SubscriptExpression;
use Microsoft\PhpParser\Node\Expression\ThrowExpression;
use Microsoft\PhpParser\Node\Expression\Variable;
use Microsoft\PhpParser\Node\MethodDeclaration;
use Microsoft\PhpParser\Node\Statement\CompoundStatementNode;
use Microsoft\PhpParser\Node\Statement\ExpressionStatement;
use Microsoft\PhpParser\Node\Statement\IfStatementNode;
use Microsoft\PhpParser\Node\Statement\ReturnStatement;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Token;
use Microsoft\PhpParser\TokenKind;

/**
 * Proves a narrow, exact response shape without executing application PHP.
 *
 * Only straight-line `$this->body = ['literal' => ...]` assignments followed
 * by literal-key additions are accepted, optionally interrupted by early-exit
 * guards (`if (...) { return $this; }` without else). The names describe the
 * path that reaches the end of the method; each guard is reported separately.
 * Anything else that requires control-flow, alias, helper-call, or expression
 * evaluation stays explicitly unsupported.
 */
final class ResourceResponseShapeExtractor
{
    public function extract(MethodDeclaration $method, string $source): ResourceResponseShape
    {
        $body = $method->compoundStatementOrSemicolon;
        if (!$body instanceof CompoundStatementNode) {
            return ResourceResponseShape::unsupported(ResourceResponseShape::REASON_NO_COMPLETE_ASSIGNMENT);
        }

        $names = [];
        $valueRanges = [];
        $complete = false;
        $returned = false;
        $earlyExits = [];
        foreach ($body->statements as $statement) {
            if ($returned) {
                return ResourceResponseShape::unsupported(ResourceResponseShape::REASON_COMPLEX_CONTROL_FLOW);
            }
            if ($statement instanceof IfStatementNode) {
                $exit = $this->earlyExit($statement, $source, $complete ? $names : null);
                if ($exit === null) {
                    return ResourceResponseShape::unsupported(ResourceResponseShape::REASON_COMPLEX_CONTROL_FLOW);
                }
                $earlyExits[] = $exit;
                continue;
            }
            if ($statement instanceof ReturnStatement) {
                if (!$this->supportedReturn($statement)) {
                    return ResourceResponseShape::unsupported(ResourceResponseShape::REASON_UNSUPPORTED_RETURN);
                }
                $returned = true;
                continue;
            }
            if (!$statement instanceof ExpressionStatement) {
                return ResourceResponseShape::unsupported(ResourceResponseShape::REASON_COMPLEX_CONTROL_FLOW);
            }

            $expression = $statement->expression;
            if ($expression instanceof AssignmentExpression) {
                $left = $expression->leftOperand;
                if ($left instanceof MemberAccessExpression && $this->isThisBody($left, $source)) {
                    if (!$this->isSimpleAssignment($expression)) {
                        return ResourceResponseShape::unsupported(ResourceResponseShape::REASON_DYNAMIC_ASSIGNMENT);
                    }
                    $literalValues = $this->literalArrayValues($expression->rightOperand);
                    if ($literalValues === null) {
                        return ResourceResponseShape::unsupported(ResourceResponseShape::REASON_DYNAMIC_ASSIGNMENT);
                    }
                    // A `$this` helper call in the right-hand side runs before the property is written,
                    // so whatever it does to the body is replaced by these literal keys.
                    $names = array_map('strval', array_keys($literalValues));
                    $valueRanges = array_map($this->range(...), $literalValues);
                    $complete = true;
                    continue;
                }
                if ($left instanceof SubscriptExpression && $this->isThisBodySubscript($left, $source)) {
                    if (!$complete || !$this->isSimpleAssignment($expression)) {
                        return ResourceResponseShape::unsupported(
                            ResourceResponseShape::REASON_NO_COMPLETE_ASSIGNMENT,
                        );
                    }
                    $key = $left->accessExpression;
                    if (!$key instanceof StringLiteral) {
                        return ResourceResponseShape::unsupported(ResourceResponseShape::REASON_DYNAMIC_KEY);
                    }
                    $keyName = $key->getStringContentsText();
                    if ($this->isIntegerArrayKey($keyName)) {
                        return ResourceResponseShape::unsupported(ResourceResponseShape::REASON_DYNAMIC_KEY);
                    }
                    if (
                        $this->containsThisBody($expression->rightOperand, $source)
                        || $this->containsSelfMethodCall($expression->rightOperand)
                    ) {
                        return ResourceResponseShape::unsupported(
                            ResourceResponseShape::REASON_UNSUPPORTED_BODY_USE,
                        );
                    }
                    $names[] = $keyName;
                    $valueRanges[$keyName] = $this->range($expression->rightOperand);
                    continue;
                }
            }

            if ($this->containsThisBody($expression, $source)) {
                return ResourceResponseShape::unsupported(ResourceResponseShape::REASON_UNSUPPORTED_BODY_USE);
            }
            if ($this->containsSelfMethodCall($expression)) {
                return ResourceResponseShape::unsupported(ResourceResponseShape::REASON_SELF_METHOD_CALL);
            }
        }

        if (!$complete) {
            return ResourceResponseShape::unsupported(ResourceResponseShape::REASON_NO_COMPLETE_ASSIGNMENT);
        }

        return ResourceResponseShape::complete($names, $earlyExits, $valueRanges);
    }

    /**
     * Full `$this->body = ...` assignments that would replace keys an `#[Embed]` placed before the method ran.
     *
     * Compound operators and right-hand sides that read `$this->body` keep those keys and are skipped.
     * Closures, arrow functions and anonymous classes are not entered: they may never run, or their
     * `$this` is another object. Assignments made inside helper methods are not seen.
     *
     * @return list<array{line: int, earlyExit: bool}>
     */
    public function bodyReplacements(MethodDeclaration $method, string $source): array
    {
        $body = $method->compoundStatementOrSemicolon;
        if (!$body instanceof CompoundStatementNode) {
            return [];
        }
        $replacements = [];
        foreach ($this->methodLevelNodes($body) as $node) {
            if (
                !$node instanceof AssignmentExpression
                || !$node->leftOperand instanceof MemberAccessExpression
                || !$this->isThisBody($node->leftOperand, $source)
                || !$this->isSimpleAssignment($node)
                || $this->containsThisBody($node->rightOperand, $source)
            ) {
                continue;
            }
            $replacements[] = [
                'line' => substr_count($source, "\n", 0, $node->getStartPosition()) + 1,
                'earlyExit' => $this->insideExitingBlock($node, $body),
            ];
        }

        return $replacements;
    }

    /**
     * @param list<string>|null $namesSoFar
     * @return array{line: int, bodyAssigned: bool, names: list<string>|null}|null
     */
    private function earlyExit(IfStatementNode $guard, string $source, ?array $namesSoFar): ?array
    {
        if ($guard->elseIfClauses !== [] || $guard->elseClause !== null) {
            return null;
        }
        // The condition runs on the main path too, so it must not call helpers or touch the body.
        if (
            $this->containsSelfMethodCall($guard->expression)
            || $this->containsThisBody($guard->expression, $source)
        ) {
            return null;
        }
        $statements = $guard->statements instanceof CompoundStatementNode
            ? $guard->statements->statements
            : (is_array($guard->statements) ? $guard->statements : [$guard->statements]);
        $last = array_pop($statements);
        if (!$last instanceof ReturnStatement || !$this->supportedReturn($last)) {
            return null;
        }
        $assigned = null;
        foreach ($statements as $statement) {
            if (!$statement instanceof ExpressionStatement) {
                return null;
            }
            $expression = $statement->expression;
            if (
                $expression instanceof AssignmentExpression
                && $expression->leftOperand instanceof MemberAccessExpression
                && $this->isThisBody($expression->leftOperand, $source)
            ) {
                $literalValues = $this->isSimpleAssignment($expression)
                    ? $this->literalArrayValues($expression->rightOperand) : null;
                if ($assigned !== null || $literalValues === null) {
                    return null;
                }
                $assigned = array_map('strval', array_keys($literalValues));
                continue;
            }
            if ($this->containsThisBody($expression, $source) || $this->containsSelfMethodCall($expression)) {
                return null;
            }
        }
        $names = $assigned ?? $namesSoFar;
        if ($names !== null) {
            sort($names);
        }

        return [
            'line' => substr_count($source, "\n", 0, $guard->getStartPosition()) + 1,
            'bodyAssigned' => $assigned !== null,
            'names' => $names === null ? null : array_values(array_unique($names)),
        ];
    }

    /** @return iterable<Node> descendants, without entering closures, arrow functions or anonymous classes */
    private function methodLevelNodes(Node $node): iterable
    {
        foreach ($node->getChildNodes() as $child) {
            if (
                $child instanceof AnonymousFunctionCreationExpression
                || $child instanceof ArrowFunctionCreationExpression
                || $child instanceof ObjectCreationExpression && $child->classMembers !== null
            ) {
                continue;
            }
            yield $child;
            yield from $this->methodLevelNodes($child);
        }
    }

    /**
     * Whether the node sits in a statement block, short of the method body, that ends by leaving the method
     * with `return` or `throw`: an `if`, `elseif` or `else` block (braced or alternative syntax), or any
     * nested braced block. Such an assignment does not reach the end of the method.
     */
    private function insideExitingBlock(Node $node, CompoundStatementNode $methodBody): bool
    {
        $child = $node;
        for ($parent = $node->parent; $parent !== null && $parent !== $methodBody; $parent = $parent->parent) {
            $statements = match (true) {
                $parent instanceof CompoundStatementNode => $parent->statements,
                ($parent instanceof IfStatementNode || $parent instanceof ElseIfClauseNode
                    || $parent instanceof ElseClauseNode) && is_array($parent->statements)
                    && in_array($child, $parent->statements, true) => $parent->statements,
                default => null,
            };
            if ($statements !== null && $this->leavesMethod(end($statements))) {
                return true;
            }
            $child = $parent;
        }

        return false;
    }

    private function leavesMethod(mixed $statement): bool
    {
        return $statement instanceof ReturnStatement
            || $statement instanceof ExpressionStatement && $statement->expression instanceof ThrowExpression;
    }

    private function supportedReturn(ReturnStatement $return): bool
    {
        if ($return->expression === null) {
            return true;
        }

        return $return->expression instanceof Variable && $return->expression->getName() === 'this';
    }

    private function isSimpleAssignment(AssignmentExpression $assignment): bool
    {
        return $assignment->operator->kind === TokenKind::EqualsToken;
    }

    /** @return array<string, Node>|null */
    private function literalArrayValues(Node $node): ?array
    {
        if (!$node instanceof ArrayCreationExpression) {
            return null;
        }

        if ($node->arrayElements === null) {
            return [];
        }

        $values = [];
        foreach ($node->arrayElements->getElements() as $element) {
            if (
                !$element instanceof ArrayElement
                || $element->dotDotDot instanceof Token
                || !$element->elementKey instanceof StringLiteral
            ) {
                return null;
            }
            $key = $element->elementKey->getStringContentsText();
            if ($this->isIntegerArrayKey($key)) {
                return null;
            }
            // A repeated key keeps its last value, as PHP does.
            $values[$key] = $element->elementValue;
        }

        return $values;
    }

    /** @return array{int, int} */
    private function range(Node $node): array
    {
        return [$node->getStartPosition(), $node->getEndPosition()];
    }

    private function isIntegerArrayKey(string $key): bool
    {
        return (string) (int) $key === $key;
    }

    private function isThisBodySubscript(SubscriptExpression $subscript, string $source): bool
    {
        return $subscript->postfixExpression instanceof MemberAccessExpression
            && $this->isThisBody($subscript->postfixExpression, $source);
    }

    private function isThisBody(MemberAccessExpression $member, string $source): bool
    {
        return $member->memberName->getText($source) === 'body'
            && $member->dereferencableExpression instanceof Variable
            && $member->dereferencableExpression->getName() === 'this';
    }

    private function containsThisBody(Node $node, string $source): bool
    {
        if ($node instanceof MemberAccessExpression && $this->isThisBody($node, $source)) {
            return true;
        }
        foreach ($node->getDescendantNodes() as $descendant) {
            if ($descendant instanceof MemberAccessExpression && $this->isThisBody($descendant, $source)) {
                return true;
            }
        }

        return false;
    }

    private function containsSelfMethodCall(Node $node): bool
    {
        if ($node instanceof CallExpression && $this->isSelfMethodCall($node)) {
            return true;
        }
        foreach ($node->getDescendantNodes() as $descendant) {
            if ($descendant instanceof CallExpression && $this->isSelfMethodCall($descendant)) {
                return true;
            }
        }

        return false;
    }

    private function isSelfMethodCall(CallExpression $call): bool
    {
        $callable = $call->callableExpression;

        return $callable instanceof MemberAccessExpression
            && $callable->dereferencableExpression instanceof Variable
            && $callable->dereferencableExpression->getName() === 'this';
    }
}
