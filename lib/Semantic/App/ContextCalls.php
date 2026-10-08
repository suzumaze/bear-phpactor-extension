<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\App;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\ObjectCreationExpression;
use Microsoft\PhpParser\Node\Expression\ParenthesizedExpression;
use Microsoft\PhpParser\Node\Expression\ScopedPropertyAccessExpression;
use Microsoft\PhpParser\Node\QualifiedName;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ParserNodes;

/** The BEAR.Package calls that take an application context, and where that argument sits. */
final class ContextCalls
{
    private function __construct()
    {
    }

    public static function isContextCall(CallExpression $call, string $contents): bool
    {
        $callee = self::unwrap($call->callableExpression);
        if ($callee instanceof ScopedPropertyAccessExpression) {
            return $callee->scopeResolutionQualifier instanceof QualifiedName
                && self::isNamed($callee->scopeResolutionQualifier, 'BEAR\\Package\\Injector')
                && in_array(
                    strtolower(ParserNodes::text($callee->memberName, $contents) ?? ''),
                    ['getinstance', 'getoverrideinstance'],
                    true,
                );
        }
        if ($callee instanceof ObjectCreationExpression && $callee->classTypeDesignator instanceof QualifiedName) {
            return self::isNamed($callee->classTypeDesignator, 'BEAR\\Package\\Compiler\\Bootstrap');
        }

        return false;
    }

    public static function contextArgument(CallExpression $call, string $contents): ?Node
    {
        $arguments = ParserNodes::elements($call->argumentExpressionList);
        foreach ($arguments as $argument) {
            if (!$argument instanceof ArgumentExpression || $argument->dotDotDotToken !== null) {
                return null;
            }
        }
        $named = false;
        foreach ($arguments as $argument) {
            $name = ParserNodes::text($argument->name, $contents);
            $named = $named || $name !== null;
            // Both BEAR.Package entry points take appName first and context second.
            if ($name === 'context') {
                return ParserNodes::optional($argument->expression);
            }
        }
        if ($named) {
            return null;
        }
        $argument = $arguments[1] ?? null;
        if ($argument instanceof ArgumentExpression) {
            return ParserNodes::optional($argument->expression);
        }

        return null;
    }

    /**
     * The argument expressions up to the first named or spread one, which is as far as a position
     * still maps to a parameter.
     *
     * @return list<Node>
     */
    public static function positionalArguments(CallExpression $call, string $contents): array
    {
        $expressions = [];
        foreach (ParserNodes::elements($call->argumentExpressionList) as $argument) {
            $expression = $argument instanceof ArgumentExpression ? ParserNodes::optional($argument->expression) : null;
            if (
                $expression === null
                || $argument->dotDotDotToken !== null
                || ParserNodes::text($argument->name, $contents) !== null
            ) {
                break;
            }
            $expressions[] = $expression;
        }

        return $expressions;
    }

    public static function unwrap(Node $node): Node
    {
        while ($node instanceof ParenthesizedExpression) {
            $node = $node->expression;
        }

        return $node;
    }

    private static function isNamed(QualifiedName $name, string $class): bool
    {
        return strcasecmp((string) $name->getResolvedName(), $class) === 0;
    }
}
