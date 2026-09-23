<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\JsonSchema;

use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\DelimitedList\ArgumentExpressionList;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Parser;
use Microsoft\PhpParser\Token;
use Phpactor\TextDocument\TextDocument;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaQuery;
use Suzumaze\BearPhpactor\Util\PhpAttributeName;

/**
 * Locates an explicit BEAR JsonSchema file reference at a PHP byte offset.
 */
final class JsonSchemaReferenceAtOffset
{
    private const JSON_SCHEMA_SHORT_NAME = 'JsonSchema';
    private const JSON_SCHEMA_FQN = 'BEAR\Resource\Annotation\JsonSchema';

    public function __construct(
        private StringLiteralAtOffset $stringLiteralAtOffset = new StringLiteralAtOffset(),
        private Parser $parser = new Parser(),
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

        return $this->referenceFromLiteral($document, $literal, $byteOffset);
    }

    /** @return list<array{int,string,int,string}> */
    public function references(TextDocument $document): array
    {
        if (
            !$document->language()->isPhp()
            || !str_contains($document->__toString(), self::JSON_SCHEMA_SHORT_NAME)
        ) {
            return [];
        }

        $references = [];
        $root = $this->parser->parseSourceFile(
            $document->__toString(),
            $document->uri()?->__toString(),
        );
        foreach ($root->getDescendantNodes() as $node) {
            if (!$node instanceof StringLiteral) {
                continue;
            }
            $reference = $this->referenceFromLiteral(
                $document,
                $node,
                $node->getStartPosition() + 1,
            );
            if ($reference !== null) {
                $references[] = $reference;
            }
        }

        usort($references, static fn (array $left, array $right): int => $left <=> $right);

        $unique = [];
        foreach ($references as $reference) {
            $unique[implode(':', [$reference[0], $reference[2], $reference[3], $reference[1]])] = $reference;
        }

        return array_values($unique);
    }

    /** @return array{int,string,int,string}|null */
    private function referenceFromLiteral(
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

        $attribute = $argumentList->getParent();
        if (!$attribute instanceof Attribute || !$this->isJsonSchemaAttribute($attribute)) {
            return null;
        }

        if ($argument->name instanceof Token) {
            $name = $argument->name->getText($text);
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
        return PhpAttributeName::is($attribute, self::JSON_SCHEMA_FQN, acceptLegacyWrittenFqn: true);
    }
}
