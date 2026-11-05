<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\InvalidTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PropertyTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/**
 * Reads `@property array{...} $body` (also `-read` and the `@phpstan-` / `@psalm-` forms) from a
 * Resource class's own docblock with PHPStan's phpdoc parser.
 */
final class ResourceDeclaredBodyReader
{
    private const TAGS = [
        '@phpstan-property', '@phpstan-property-read', '@psalm-property', '@psalm-property-read',
        '@property', '@property-read',
    ];

    private readonly Lexer $lexer;
    private readonly PhpDocParser $parser;

    public function __construct()
    {
        $config = new ParserConfig(['lines' => true, 'indexes' => true]);
        $constExprParser = new ConstExprParser($config);
        $this->lexer = new Lexer($config);
        $this->parser = new PhpDocParser($config, new TypeParser($config, $constExprParser), $constExprParser);
    }

    public function read(ClassDeclaration $class, string $source): ?ResourceDeclaredBody
    {
        $docblock = $class->getDocCommentText();
        if ($docblock === null || !str_contains($docblock, '$body')) {
            return null;
        }
        $leading = $class->getLeadingCommentAndWhitespaceText();
        $offset = $class->getFullStartPosition() + (int) strrpos($leading, $docblock);
        $firstLine = substr_count($source, "\n", 0, $offset) + 1;

        $tokens = $this->lexer->tokenize($docblock);
        $tags = array_filter(
            $this->parser->parse(new TokenIterator($tokens))->getTags(),
            static fn (PhpDocTagNode $tag): bool => in_array($tag->name, self::TAGS, true),
        );
        // PHPStan and Psalm read their own prefixed tag before the plain one, so this does too.
        usort($tags, static fn (PhpDocTagNode $left, PhpDocTagNode $right): int =>
            array_search($left->name, self::TAGS, true) <=> array_search($right->name, self::TAGS, true));
        foreach ($tags as $tag) {
            $line = $firstLine + (int) $tag->getAttribute('startLine') - 1;
            if ($tag->value instanceof InvalidTagValueNode && str_contains($tag->value->value, '$body')) {
                return ResourceDeclaredBody::unreadable($tag->name, $line);
            }
            if ($tag->value instanceof PropertyTagValueNode && $tag->value->propertyName === '$body') {
                return ResourceDeclaredBody::declared($tag->name, $line, $this->variants($tag->value->type, $tokens));
            }
        }

        return null;
    }

    /**
     * @param list<array{string, int, int}> $tokens
     * @return list<array{keys: list<array{name: string, type: string, optional: bool}>, open?: true}
     *     |array{type: string}>
     */
    private function variants(TypeNode $type, array $tokens): array
    {
        $members = match (true) {
            $type instanceof UnionTypeNode => $type->types,
            $type instanceof NullableTypeNode => [$type->type, new IdentifierTypeNode('null')],
            default => [$type],
        };
        $variants = [];
        foreach ($members as $member) {
            $keys = $member instanceof ArrayShapeNode ? $this->keys($member, $tokens) : null;
            if ($keys === null) {
                $variants[] = ['type' => $this->text($member, $tokens)];
                continue;
            }
            $variants[] = $member->sealed ? ['keys' => $keys] : ['keys' => $keys, 'open' => true];
        }

        return $variants;
    }

    /**
     * @param list<array{string, int, int}> $tokens
     * @return list<array{name: string, type: string, optional: bool}>|null null unless every key is a string
     */
    private function keys(ArrayShapeNode $shape, array $tokens): ?array
    {
        if (!in_array($shape->kind, [ArrayShapeNode::KIND_ARRAY, ArrayShapeNode::KIND_NON_EMPTY_ARRAY], true)) {
            return null;
        }
        $keys = [];
        foreach ($shape->items as $item) {
            $name = match (true) {
                $item->keyName instanceof IdentifierTypeNode => $item->keyName->name,
                $item->keyName instanceof ConstExprStringNode => $item->keyName->value,
                default => null,
            };
            // PHP turns a decimal-integer string key into an int key.
            if ($name === null || (string) (int) $name === $name) {
                return null;
            }
            $keys[] = [
                'name' => $name,
                'type' => $this->text($item->valueType, $tokens),
                'optional' => $item->optional,
            ];
        }

        return $keys;
    }

    /**
     * The type as written in the docblock, on one line.
     *
     * @param list<array{string, int, int}> $tokens
     */
    private function text(TypeNode $type, array $tokens): string
    {
        $start = $type->getAttribute('startIndex');
        $end = $type->getAttribute('endIndex');
        if (!is_int($start) || !is_int($end)) {
            return (string) $type;
        }
        $text = '';
        for ($index = $start; $index <= $end; $index++) {
            $text .= $tokens[$index][Lexer::TYPE_OFFSET] === Lexer::TOKEN_PHPDOC_EOL
                ? ' ' : $tokens[$index][Lexer::VALUE_OFFSET];
        }

        return trim((string) preg_replace(['/\s+/', '/([{<(]) | ([}>)])/'], [' ', '$1$2'], $text));
    }
}
