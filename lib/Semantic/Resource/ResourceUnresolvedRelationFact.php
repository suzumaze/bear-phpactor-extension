<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;

/**
 * A `#[Link]` or `#[Embed]` whose target could not be read from source, kept so that
 * callers can see what was left out instead of getting a silently shorter relation list.
 *
 * `reason` is `not_literal` when the target is an expression such as `self::BASE . '/x'`,
 * `missing` when the attribute has no target argument, and `invalid_uri` when the target
 * is a string literal that is not a Resource URI.
 */
final readonly class ResourceUnresolvedRelationFact
{
    public function __construct(
        public string $kind,
        public string $rel,
        public ResourceUri $sourceUri,
        public string $sourceMethod,
        /** `src` for an embed, `href` for a link */
        public string $argument,
        /** 'not_literal'|'missing'|'invalid_uri' */
        public string $reason,
        /** Source text of the target, cut to a bounded length; null when the argument is missing */
        public ?string $expression,
        public string $sourceFile,
        public int $byteOffset,
        /** 1-based line of $byteOffset */
        public int $line,
    ) {
    }
}
