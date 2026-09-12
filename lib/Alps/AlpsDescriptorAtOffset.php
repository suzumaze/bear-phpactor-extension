<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Alps;

use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\DelimitedList\ArgumentExpressionList;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\QualifiedName;
use Phpactor\TextDocument\TextDocument;
use Phpactor\WorseReflection\Core\Util\NodeUtil;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;

/**
 * Locates an ALPS descriptor ID at a PHP byte offset without resolving it.
 */
final class AlpsDescriptorAtOffset
{
    private const ALPS_SHORT_NAME = 'Alps';
    private const ALPS_FQN = 'BEAR\ApiDoc\Annotation\Alps';

    public function __construct(
        private StringLiteralAtOffset $stringLiteralAtOffset = new StringLiteralAtOffset(),
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
        if (!$attribute->name instanceof QualifiedName) {
            return false;
        }

        // Keep the explicit no-leading-backslash spelling for compatibility
        // with generated BEAR code, then resolve short names and aliases using
        // PHP namespace/import rules. A coincidental Other\Alps must not match.
        $writtenName = ltrim((string) NodeUtil::nameFromTokenOrQualifiedName($attribute, $attribute->name), '\\');
        if ($writtenName === self::ALPS_FQN) {
            return true;
        }

        $resolvedName = ltrim((string) $attribute->name->getResolvedName(), '\\');

        return $resolvedName === self::ALPS_FQN;
    }
}
