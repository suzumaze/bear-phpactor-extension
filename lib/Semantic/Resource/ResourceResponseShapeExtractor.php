<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\ArrayElement;
use Microsoft\PhpParser\Node\Expression\ArrayCreationExpression;
use Microsoft\PhpParser\Node\Expression\AssignmentExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\Expression\SubscriptExpression;
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
                    $literalNames = $this->literalArrayNames($expression->rightOperand);
                    if ($literalNames === null) {
                        return ResourceResponseShape::unsupported(ResourceResponseShape::REASON_DYNAMIC_ASSIGNMENT);
                    }
                    // A `$this` helper call in the right-hand side runs before the property is written,
                    // so whatever it does to the body is replaced by these literal keys.
                    $names = $literalNames;
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

        return ResourceResponseShape::complete($names, $earlyExits);
    }

    /**
     * Full `$this->body = ...` assignments that would replace keys an `#[Embed]` placed before the method ran.
     *
     * Compound operators and right-hand sides that read `$this->body` keep those keys and are skipped.
     * Assignments made inside helper methods are not seen.
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
        foreach ($body->getDescendantNodes() as $node) {
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
        if ($this->containsSelfMethodCall($guard->expression)) {
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
                $literalNames = $this->isSimpleAssignment($expression)
                    ? $this->literalArrayNames($expression->rightOperand) : null;
                if ($assigned !== null || $literalNames === null) {
                    return null;
                }
                $assigned = $literalNames;
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

    private function insideExitingBlock(Node $node, CompoundStatementNode $methodBody): bool
    {
        for ($parent = $node->parent; $parent !== null && $parent !== $methodBody; $parent = $parent->parent) {
            if ($parent instanceof CompoundStatementNode && $parent->parent instanceof IfStatementNode) {
                $statements = $parent->statements;
                if (end($statements) instanceof ReturnStatement) {
                    return true;
                }
            }
        }

        return false;
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

    /** @return list<string>|null */
    private function literalArrayNames(Node $node): ?array
    {
        if (!$node instanceof ArrayCreationExpression) {
            return null;
        }

        if ($node->arrayElements === null) {
            return [];
        }

        $names = [];
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
            $names[] = $key;
        }

        return $names;
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
