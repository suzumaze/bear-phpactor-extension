<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\ArrayElement;
use Microsoft\PhpParser\Node\Expression\ArrayCreationExpression;
use Microsoft\PhpParser\Node\Expression\CastExpression;
use Microsoft\PhpParser\Node\Expression\ObjectCreationExpression;
use Microsoft\PhpParser\Node\Expression\ParenthesizedExpression;
use Microsoft\PhpParser\Node\Expression\ScopedPropertyAccessExpression;
use Microsoft\PhpParser\Node\Expression\UnaryOpExpression;
use Microsoft\PhpParser\Node\NumericLiteral;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\ReservedWord;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Token;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\PhpStringLiteral;

/**
 * Source-only readers for Ray.Di bind-chain argument values; nothing is evaluated.
 */
final class StaticBindingValue
{
    private const RAY_SCOPE_CONSTANTS = [
        'SINGLETON' => 'Singleton',
        'PROTOTYPE' => 'Prototype',
    ];

    private const CAST_TYPES = [
        'string' => 'string',
        'binary' => 'string',
        'int' => 'integer',
        'integer' => 'integer',
        'bool' => 'boolean',
        'boolean' => 'boolean',
        'float' => 'double',
        'double' => 'double',
        'real' => 'double',
        'array' => 'array',
        'object' => 'object',
    ];

    private function __construct()
    {
    }

    /**
     * Returns the raw Ray.Di scope string from a literal or a `Ray\Di\Scope` constant.
     */
    public static function scope(?Node $expression, string $source): ?string
    {
        if ($expression instanceof StringLiteral) {
            return PhpStringLiteral::decode($expression);
        }
        if (
            !$expression instanceof ScopedPropertyAccessExpression
            || !$expression->scopeResolutionQualifier instanceof QualifiedName
            || !$expression->memberName instanceof Token
        ) {
            return null;
        }
        $class = ltrim((string) $expression->scopeResolutionQualifier->getResolvedName(), '\\');

        return $class === 'Ray\\Di\\Scope'
            ? self::RAY_SCOPE_CONSTANTS[$expression->memberName->getText($source)] ?? null
            : null;
    }

    public static function isNull(?Node $expression, string $source): bool
    {
        return $expression instanceof ReservedWord
            && strtolower($expression->children->getText($source)) === 'null';
    }

    /**
     * Accepts `toConstructor()` name mappings: a literal string or a literal array of static strings.
     */
    public static function isNameMapping(?Node $expression, string $source): bool
    {
        if ($expression instanceof StringLiteral) {
            return true;
        }
        if (!$expression instanceof ArrayCreationExpression) {
            return false;
        }
        if ($expression->arrayElements === null) {
            return true;
        }
        foreach ($expression->arrayElements->getElements() as $element) {
            if (
                !$element instanceof ArrayElement
                || $element->dotDotDot instanceof Token
                || RayModuleCall::staticName($element->elementKey, $source) === null
                || RayModuleCall::staticName($element->elementValue, $source) === null
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Infers a PHP `gettype()` name from the expression form only; null when the form does not fix it.
     */
    public static function valueType(?Node $expression, string $source): ?string
    {
        return match (true) {
            $expression instanceof ParenthesizedExpression => self::valueType($expression->expression, $source),
            $expression instanceof StringLiteral => 'string',
            $expression instanceof NumericLiteral => self::numericType($expression->getText()),
            $expression instanceof UnaryOpExpression
                && in_array($expression->operator->getText($source), ['-', '+'], true)
                && $expression->operand instanceof NumericLiteral => self::numericType($expression->operand->getText()),
            $expression instanceof ReservedWord => match (strtolower($expression->children->getText($source))) {
                'null' => 'NULL',
                'true', 'false' => 'boolean',
                default => null,
            },
            $expression instanceof ArrayCreationExpression => 'array',
            $expression instanceof ScopedPropertyAccessExpression
                && RayModuleCall::staticName($expression, $source) !== null => 'string',
            $expression instanceof CastExpression => self::CAST_TYPES[
                strtolower(trim($expression->castType->getText($source), "() \t"))
            ] ?? null,
            $expression instanceof ObjectCreationExpression => 'object',
            default => null,
        };
    }

    private static function numericType(string $literal): string
    {
        $literal = strtolower(trim($literal));

        return !str_starts_with($literal, '0x') && strpbrk($literal, '.e') !== false ? 'double' : 'integer';
    }
}
