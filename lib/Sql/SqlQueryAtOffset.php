<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Sql;

use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\DelimitedList\ArgumentExpressionList;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Parser;
use Microsoft\PhpParser\PhpTokenizer;
use Microsoft\PhpParser\Token;
use Microsoft\PhpParser\TokenKind;
use Phpactor\TextDocument\TextDocument;
use Phpactor\WorseReflection\Core\Util\NodeUtil;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;

/**
 * Locates a static Ray.MediaQuery / Ray.QueryModule SQL query ID without resolving it.
 */
final class SqlQueryAtOffset
{
    private const DB_QUERY_SHORT_NAME = 'DbQuery';
    private const DB_QUERY_FQN = 'Ray\MediaQuery\Annotation\DbQuery';

    public function __construct(
        private StringLiteralAtOffset $stringLiteralAtOffset = new StringLiteralAtOffset(),
        private Parser $parser = new Parser(),
    ) {
    }

    /**
     * @return array{int,string,int}|null Content start, query ID, and content end byte offset.
     */
    public function __invoke(TextDocument $document, int $byteOffset): ?array
    {
        if (!$document->language()->isPhp()) {
            return null;
        }

        $text = $document->__toString();
        if (!str_contains($text, self::DB_QUERY_SHORT_NAME) && !str_contains($text, '@Query')) {
            return null;
        }

        $attribute = $this->fromDbQueryAttribute($document, $byteOffset);
        if ($attribute !== null) {
            return $attribute;
        }

        return $this->fromQueryAnnotation($text, $byteOffset);
    }

    /**
     * Return every statically identifiable SQL query ID in source order.
     *
     * @return list<array{int,string,int}>
     */
    public function references(TextDocument $document): array
    {
        if (!$document->language()->isPhp()) {
            return [];
        }

        $text = $document->__toString();
        if (!str_contains($text, self::DB_QUERY_SHORT_NAME) && !str_contains($text, '@Query')) {
            return [];
        }

        $references = [];
        if (str_contains($text, self::DB_QUERY_SHORT_NAME)) {
            $root = $this->parser->parseSourceFile($text, $document->uri()?->__toString());
            foreach ($root->getDescendantNodes() as $node) {
                if (!$node instanceof StringLiteral) {
                    continue;
                }

                $reference = $this->referenceFromDbQueryLiteral(
                    $document,
                    $node,
                    $node->getStartPosition() + 1,
                );
                if ($reference !== null) {
                    $references[] = $reference;
                }
            }
        }

        if (str_contains($text, '@Query')) {
            array_push($references, ...$this->queryAnnotationReferences($text));
        }

        usort(
            $references,
            static fn (array $left, array $right): int => $left <=> $right,
        );

        $unique = [];
        foreach ($references as $reference) {
            $unique[$reference[0] . ':' . $reference[2] . ':' . $reference[1]] = $reference;
        }

        return array_values($unique);
    }

    /** @return array{int,string,int}|null */
    private function fromDbQueryAttribute(TextDocument $document, int $byteOffset): ?array
    {
        $literal = $this->stringLiteralAtOffset->literal($document, $byteOffset);
        if ($literal === null) {
            return null;
        }

        return $this->referenceFromDbQueryLiteral($document, $literal, $byteOffset);
    }

    /** @return array{int,string,int}|null */
    private function referenceFromDbQueryLiteral(
        TextDocument $document,
        StringLiteral $literal,
        int $byteOffset,
    ): ?array {
        $text = $document->__toString();
        $opening = substr($text, $literal->getStartPosition(), 1);
        if ($opening !== "'" && $opening !== '"') {
            return null;
        }

        $contentStart = $literal->getStartPosition() + 1;
        $contentEnd = $literal->getEndPosition() - 1;
        if ($byteOffset < $contentStart || $byteOffset >= $contentEnd) {
            return null;
        }

        $argument = $literal->getParent();
        if (!$argument instanceof ArgumentExpression || $argument->expression !== $literal) {
            return null;
        }

        $argumentList = $argument->getParent();
        if (!$argumentList instanceof ArgumentExpressionList) {
            return null;
        }

        if ($argument->name instanceof Token) {
            if ($argument->name->getText($text) !== 'id') {
                return null;
            }
        } elseif (!isset($argumentList->children[0]) || $argumentList->children[0] !== $argument) {
            return null;
        }

        $attribute = $argumentList->getParent();
        if (!$attribute instanceof Attribute || !$this->isDbQueryAttribute($attribute)) {
            return null;
        }

        return [$contentStart, $literal->getStringContentsText(), $contentEnd];
    }

    private function isDbQueryAttribute(Attribute $attribute): bool
    {
        if (!$attribute->name instanceof QualifiedName) {
            return false;
        }

        $writtenName = ltrim((string) NodeUtil::nameFromTokenOrQualifiedName($attribute, $attribute->name), '\\');
        if ($writtenName === self::DB_QUERY_FQN) {
            return true;
        }

        $resolvedName = ltrim((string) $attribute->name->getResolvedName(), '\\');

        return $resolvedName === self::DB_QUERY_FQN;
    }

    /** @return array{int,string,int}|null */
    private function fromQueryAnnotation(string $text, int $byteOffset): ?array
    {
        foreach ($this->queryAnnotationReferences($text) as $reference) {
            if ($byteOffset >= $reference[0] && $byteOffset < $reference[2]) {
                return $reference;
            }
        }

        return null;
    }

    /** @return list<array{int,string,int}> */
    private function queryAnnotationReferences(string $text): array
    {
        $references = [];
        foreach (PhpTokenizer::getTokensArrayFromContent($text, null, 0, false) as $token) {
            if ($token->kind !== TokenKind::DocCommentToken) {
                continue;
            }

            $comment = $token->getText($text);
            if ($comment === null || $comment === '') {
                continue;
            }

            array_push($references, ...$this->referencesFromDocComment($comment, $token->start));
        }

        return $references;
    }

    /**
     * @return list<array{int,string,int}>
     */
    private function referencesFromDocComment(string $comment, int $commentOffset): array
    {
        if (!str_starts_with($comment, '/**')) {
            return [];
        }

        if (
            preg_match_all(
                '/@Query\s*\(\s*([\'"])(?<name>[^\'"]+)\1/',
                $comment,
                $matches,
                PREG_OFFSET_CAPTURE,
            ) === false
        ) {
            return [];
        }

        $references = [];
        foreach ($matches['name'] as [$name, $relativeOffset]) {
            $start = $commentOffset + $relativeOffset;
            $end = $start + strlen($name);
            $references[] = [$start, $name, $end];
        }

        return $references;
    }
}
