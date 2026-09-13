<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Sql;

use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\DelimitedList\ArgumentExpressionList;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\QualifiedName;
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

    /** @return array{int,string,int}|null */
    private function fromDbQueryAttribute(TextDocument $document, int $byteOffset): ?array
    {
        $literal = $this->stringLiteralAtOffset->literal($document, $byteOffset);
        if ($literal === null) {
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
            if ($argument->name->getText($document->__toString()) !== 'id') {
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
        foreach (PhpTokenizer::getTokensArrayFromContent($text, null, 0, false) as $token) {
            if ($token->kind !== TokenKind::DocCommentToken) {
                continue;
            }

            $comment = $token->getText($text);
            if ($comment === null || $comment === '') {
                continue;
            }

            $commentEnd = $token->start + strlen($comment);
            if ($byteOffset < $token->start || $byteOffset >= $commentEnd) {
                continue;
            }

            $reference = $this->fromDocComment($comment, $token->start, $byteOffset);
            if ($reference !== null) {
                return $reference;
            }
        }

        return null;
    }

    /**
     * @return array{int,string,int}|null
     */
    private function fromDocComment(string $comment, int $commentOffset, int $byteOffset): ?array
    {
        if (!str_starts_with($comment, '/**')) {
            return null;
        }

        if (
            preg_match_all(
                '/@Query\s*\(\s*([\'"])(?<name>[^\'"]+)\1/',
                $comment,
                $matches,
                PREG_OFFSET_CAPTURE,
            ) === false
        ) {
            return null;
        }

        foreach ($matches['name'] as [$name, $relativeOffset]) {
            $start = $commentOffset + $relativeOffset;
            $end = $start + strlen($name);
            if ($byteOffset >= $start && $byteOffset < $end) {
                return [$start, $name, $end];
            }
        }

        return null;
    }
}
