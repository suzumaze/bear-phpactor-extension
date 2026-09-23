<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Alps;

use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\DelimitedList\ArgumentExpressionList;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Parser;
use Phpactor\TextDocument\TextDocument;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Util\PhpAttributeName;

/**
 * Locates an ALPS descriptor ID at a PHP byte offset without resolving it.
 */
final class AlpsDescriptorAtOffset
{
    private const ALPS_SHORT_NAME = 'Alps';
    private const ALPS_FQN = 'BEAR\ApiDoc\Annotation\Alps';

    public function __construct(
        private StringLiteralAtOffset $stringLiteralAtOffset = new StringLiteralAtOffset(),
        private Parser $parser = new Parser(),
    ) {
    }

    /**
     * @return array{int,string,int}|null Content start, descriptor ID, and content end byte offset.
     */
    public function __invoke(TextDocument $document, int $byteOffset): ?array
    {
        if (!str_contains($document->__toString(), self::ALPS_SHORT_NAME)) {
            return null;
        }

        $literal = $this->stringLiteralAtOffset->literal($document, $byteOffset);
        if ($literal === null) {
            return null;
        }

        return $this->referenceFromLiteral($document, $literal, $byteOffset);
    }

    /** @return list<array{int,string,int}> */
    public function references(TextDocument $document): array
    {
        if (!$document->language()->isPhp() || !str_contains($document->__toString(), self::ALPS_SHORT_NAME)) {
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

        return $references;
    }

    /** @return array{int,string,int}|null */
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

        if (!isset($argumentList->children[0]) || $argumentList->children[0] !== $argument) {
            return null;
        }

        $attribute = $argumentList->getParent();
        if (!$attribute instanceof Attribute || !$this->isAlpsAttribute($attribute)) {
            return null;
        }

        return [$contentStart, $literal->getStringContentsText(), $contentEnd];
    }

    private function isAlpsAttribute(Attribute $attribute): bool
    {
        return PhpAttributeName::is($attribute, self::ALPS_FQN, acceptLegacyWrittenFqn: true);
    }
}
