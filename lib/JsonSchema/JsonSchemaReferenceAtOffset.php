<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\JsonSchema;

use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\DelimitedList\ArgumentExpressionList;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Token;
use Phpactor\TextDocument\TextDocument;
use Phpactor\WorseReflection\Core\Util\NodeUtil;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaQuery;

/**
 * Locates an explicit BEAR JsonSchema file reference at a PHP byte offset.
 */
final class JsonSchemaReferenceAtOffset
{
    private const JSON_SCHEMA_SHORT_NAME = 'JsonSchema';
    private const JSON_SCHEMA_FQN = 'BEAR\Resource\Annotation\JsonSchema';

    public function __construct(
        private StringLiteralAtOffset $stringLiteralAtOffset = new StringLiteralAtOffset(),
    ) {
    }

    /**
     * @return array{int,string,int,string}|null Content start, file name, content end, and schema kind.
     */
    public function __invoke(TextDocument $document, int $byteOffset): ?array
    {
        if (!str_contains($document->__toString(), self::JSON_SCHEMA_SHORT_NAME)) {
            return null;
        }

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

        $attribute = $argumentList->getParent();
        if (!$attribute instanceof Attribute || !$this->isJsonSchemaAttribute($attribute)) {
            return null;
        }

        if ($argument->name instanceof Token) {
            $name = $argument->name->getText($document->__toString());
            $kind = match ($name) {
                'schema' => SchemaQuery::KIND_RESPONSE,
                'params' => SchemaQuery::KIND_REQUEST,
                default => null,
            };
            if ($kind === null) {
                return null;
            }
        } else {
            if (!isset($argumentList->children[0]) || $argumentList->children[0] !== $argument) {
                return null;
            }
            $kind = SchemaQuery::KIND_RESPONSE;
        }

        return [$contentStart, $literal->getStringContentsText(), $contentEnd, $kind];
    }

    private function isJsonSchemaAttribute(Attribute $attribute): bool
    {
        if (!$attribute->name instanceof QualifiedName) {
            return false;
        }

        $writtenName = ltrim((string) NodeUtil::nameFromTokenOrQualifiedName($attribute, $attribute->name), '\\');
        if ($writtenName === self::JSON_SCHEMA_FQN) {
            return true;
        }

        $resolvedName = ltrim((string) $attribute->name->getResolvedName(), '\\');

        return $resolvedName === self::JSON_SCHEMA_FQN;
    }
}
