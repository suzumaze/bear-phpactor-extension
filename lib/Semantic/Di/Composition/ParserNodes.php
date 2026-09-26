<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di\Composition;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\DelimitedList;
use Microsoft\PhpParser\Token;

/**
 * Normalizes tolerant-parser members that its PHPDoc declares non-null but that are
 * null for incomplete source or short forms such as `?:`.
 */
final class ParserNodes
{
    private function __construct()
    {
    }

    public static function optional(mixed $node): ?Node
    {
        return $node instanceof Node ? $node : null;
    }

    public static function text(mixed $token, string $contents): ?string
    {
        return $token instanceof Token ? $token->getText($contents) : null;
    }

    /** @return list<mixed> */
    public static function elements(mixed $list): array
    {
        if ($list instanceof DelimitedList) {
            return iterator_to_array($list->getElements(), false);
        }

        return is_array($list) ? array_values($list) : [];
    }
}
