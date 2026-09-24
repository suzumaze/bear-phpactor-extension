<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\Expression\ScopedPropertyAccessExpression;
use Microsoft\PhpParser\Node\Expression\Variable;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Token;

/**
 * Small source-only helpers shared by Ray.Di and Ray.Aop fact extractors.
 */
final class RayModuleCall
{
    private function __construct()
    {
    }

    public static function isThisMethod(CallExpression $call, string $method, string $source): bool
    {
        $member = $call->callableExpression;

        return $member instanceof MemberAccessExpression
            && $member->memberName->getText($source) === $method
            && $member->dereferencableExpression instanceof Variable
            && $member->dereferencableExpression->getName() === 'this';
    }

    public static function methodName(CallExpression $call, string $source): ?string
    {
        $member = $call->callableExpression;

        return $member instanceof MemberAccessExpression
            ? $member->memberName->getText($source)
            : null;
    }

    /** @return list<ArgumentExpression>|null */
    public static function arguments(CallExpression $call): ?array
    {
        if ($call->argumentExpressionList === null) {
            return [];
        }
        $arguments = [];
        foreach ($call->argumentExpressionList->getElements() as $argument) {
            if (!$argument instanceof ArgumentExpression || $argument->dotDotDotToken instanceof Token) {
                return null;
            }
            $arguments[] = $argument;
        }

        return $arguments;
    }

    public static function staticName(?Node $expression, string $source): ?string
    {
        if ($expression instanceof StringLiteral) {
            return $expression->getStringContentsText();
        }
        if (!$expression instanceof ScopedPropertyAccessExpression) {
            return null;
        }
        if (
            !$expression->scopeResolutionQualifier instanceof QualifiedName
            || !$expression->memberName instanceof Token
            || strtolower($expression->memberName->getText($source)) !== 'class'
        ) {
            return null;
        }
        $resolved = $expression->scopeResolutionQualifier->getResolvedName();

        return $resolved === null ? null : ltrim((string) $resolved, '\\');
    }

    public static function chainedCall(CallExpression $call): ?CallExpression
    {
        $member = $call->parent;
        if (!$member instanceof MemberAccessExpression || $member->dereferencableExpression !== $call) {
            return null;
        }
        $parent = $member->parent;

        return $parent instanceof CallExpression && $parent->callableExpression === $member
            ? $parent
            : null;
    }

    public static function terminalCall(CallExpression $call): CallExpression
    {
        while (($next = self::chainedCall($call)) !== null) {
            $call = $next;
        }

        return $call;
    }
}
