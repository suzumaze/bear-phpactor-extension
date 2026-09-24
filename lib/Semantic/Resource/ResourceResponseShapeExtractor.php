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
use Microsoft\PhpParser\Node\Statement\ReturnStatement;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Token;
use Microsoft\PhpParser\TokenKind;

/**
 * Proves a narrow, exact response shape without executing application PHP.
 *
 * Only straight-line `$this->body = ['literal' => ...]` assignments followed
 * by literal-key additions are accepted. Anything that requires control-flow,
 * alias, helper-call, or expression evaluation stays explicitly unsupported.
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
        foreach ($body->statements as $statement) {
            if ($returned) {
                return ResourceResponseShape::unsupported(ResourceResponseShape::REASON_COMPLEX_CONTROL_FLOW);
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
                    if ($this->containsSelfMethodCall($expression->rightOperand)) {
                        return ResourceResponseShape::unsupported(ResourceResponseShape::REASON_SELF_METHOD_CALL);
                    }
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

        return ResourceResponseShape::complete($names);
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
