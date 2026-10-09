<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\App;

use Microsoft\PhpParser\FunctionLike;
use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Expression\BinaryExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\Expression\ObjectCreationExpression;
use Microsoft\PhpParser\Node\Expression\ScopedPropertyAccessExpression;
use Microsoft\PhpParser\Node\Expression\ScriptInclusionExpression;
use Microsoft\PhpParser\Node\Expression\Variable;
use Microsoft\PhpParser\Node\NumericLiteral;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\Statement\FunctionDeclaration;
use Microsoft\PhpParser\Node\StringLiteral;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ParserNodes;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\PhpStringLiteral;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceAccessPolicy;

/**
 * Names what a call invokes, so that a callable's definition and the places that call it agree on
 * one key. Only forms that can be named from the source alone get a key; anything else is null.
 */
final class ContextCallee
{
    private const MAX_PATH_DEPTH = 16;
    private const MAX_DIRNAME_LEVELS = 32;

    private function __construct()
    {
    }

    /** `Class::method()`, `(new Class())()`, `(new Class())->method()`, `$closure()`, `(require 'file')()`. */
    public static function key(CallExpression $call, string $file, WorkspaceAccessPolicy $policy): ?string
    {
        $contents = $call->getFileContents();
        $callee = ContextCalls::unwrap($call->callableExpression);
        if ($callee instanceof ScopedPropertyAccessExpression) {
            return self::qualifiedMethodKey(
                $callee->scopeResolutionQualifier,
                ParserNodes::text($callee->memberName, $contents),
            );
        }
        if ($callee instanceof ObjectCreationExpression) {
            return self::qualifiedMethodKey($callee->classTypeDesignator, '__invoke');
        }
        if ($callee instanceof MemberAccessExpression) {
            $object = ContextCalls::unwrap($callee->dereferencableExpression);

            return $object instanceof ObjectCreationExpression
                ? self::qualifiedMethodKey(
                    $object->classTypeDesignator,
                    ParserNodes::text($callee->memberName, $contents),
                )
                : null;
        }
        if ($callee instanceof Variable) {
            return self::variableKey($callee, $file);
        }
        if ($callee instanceof ScriptInclusionExpression) {
            $path = self::path($callee->expression, $file);
            $inspected = $path === null ? null : $policy->inspectExisting($path)->value;

            return $inspected === null ? null : self::fileKey($inspected->absolute);
        }

        return null;
    }

    public static function methodKey(string $class, string $method): string
    {
        return strtolower(trim($class, '\\')) . '::' . strtolower($method);
    }

    public static function fileKey(string $absolute): string
    {
        return 'file:' . $absolute;
    }

    /** A variable is only the same variable inside the function-like (or file) that holds it. */
    public static function variableKey(Variable $variable, string $file): string
    {
        return self::variableKeyIn($variable, $variable->getText(), $file);
    }

    /** The key of the variable `$name` (with its dollar sign) in the scope that contains `$anchor`. */
    public static function variableKeyIn(Node $anchor, string $name, string $file): string
    {
        $scope = self::scope($anchor)?->getStartPosition() ?? -1;

        return 'variable:' . $file . '#' . $scope . ':' . $name;
    }

    /** The method, closure, arrow function, or named function a node sits in; null at file level. */
    public static function scope(Node $node): ?Node
    {
        return $node->getFirstAncestor(FunctionLike::class, FunctionDeclaration::class);
    }

    /**
     * Evaluates a file path built from literals, `__DIR__`, `__FILE__`, `dirname()`, and `.`.
     * Anything that depends on a runtime value is null.
     */
    public static function path(Node $node, string $file, int $depth = 0): ?string
    {
        // A real require path has a handful of pieces; the bound keeps hostile input from recursing deeply.
        if ($depth > self::MAX_PATH_DEPTH) {
            return null;
        }
        $node = ContextCalls::unwrap($node);
        if ($node instanceof StringLiteral) {
            $interpolated = array_filter(
                is_array($node->children) ? $node->children : [],
                static fn (mixed $part): bool => $part instanceof Node,
            );

            return $interpolated === [] ? PhpStringLiteral::decode($node) : null;
        }
        if ($node instanceof QualifiedName) {
            return match (strtolower($node->getText())) {
                '__dir__' => dirname($file),
                '__file__' => $file,
                default => null,
            };
        }
        if ($node instanceof BinaryExpression && $node->operator->getText($node->getFileContents()) === '.') {
            $left = self::path($node->leftOperand, $file, $depth + 1);
            $right = self::path($node->rightOperand, $file, $depth + 1);

            return $left !== null && $right !== null ? $left . $right : null;
        }
        if ($node instanceof CallExpression) {
            return self::dirname($node, $file, $depth + 1);
        }

        return null;
    }

    private static function dirname(CallExpression $call, string $file, int $depth): ?string
    {
        $callee = $call->callableExpression;
        if (!$callee instanceof QualifiedName || strtolower(ltrim($callee->getText(), '\\')) !== 'dirname') {
            return null;
        }
        $arguments = ContextCalls::positionalArguments($call, $call->getFileContents());
        $declared = count(ParserNodes::elements($call->argumentExpressionList));
        if ($arguments === [] || count($arguments) > 2 || $declared !== count($arguments)) {
            return null;
        }
        $levels = 1;
        if (isset($arguments[1])) {
            $level = ContextCalls::unwrap($arguments[1]);
            if (!$level instanceof NumericLiteral || !ctype_digit($level->getText())) {
                return null;
            }
            $levels = (int) $level->getText();
            if ($levels < 1 || $levels > self::MAX_DIRNAME_LEVELS) {
                return null;
            }
        }
        $path = self::path($arguments[0], $file, $depth);
        for ($i = 0; $i < $levels && $path !== null; ++$i) {
            $path = dirname($path);
        }

        return $path;
    }

    private static function qualifiedMethodKey(mixed $class, ?string $method): ?string
    {
        if (!$class instanceof QualifiedName || $method === null || $method === '') {
            return null;
        }

        return self::methodKey((string) $class->getResolvedName(), $method);
    }
}
