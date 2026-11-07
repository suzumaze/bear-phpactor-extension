<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

/**
 * Infers the type of each value a Resource method writes into its body, from the saved file.
 *
 * This is an inference, not a proof: it can be wider or narrower than what runs. The types depend on
 * other files (entities, repositories), so they must not be cached with the Resource's own facts.
 */
interface ResourceBodyTypeResolver
{
    public const KIND_ARRAY = 'array';
    public const KIND_NULL = 'null';
    public const KIND_OBJECT = 'object';
    public const KIND_SCALAR = 'scalar';
    public const KIND_UNION = 'union';
    public const KIND_UNKNOWN = 'unknown';

    /**
     * @param array<string, array{int, int}> $valueRanges byte start and end of each value in the file
     * @return list<array{name: string, type: string, kind: string, classes: list<string>}> in name order;
     *     `kind` is `unknown` and `classes` empty when the type cannot be inferred
     */
    public function resolve(string $path, array $valueRanges): array;
}
