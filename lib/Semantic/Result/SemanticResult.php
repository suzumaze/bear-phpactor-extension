<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Result;

use LogicException;

/**
 * @template-covariant TValue
 */
final readonly class SemanticResult
{
    /**
     * @param TValue|null  $value
     * @param list<TValue> $candidates
     */
    private function __construct(
        public SemanticStatus $status,
        public mixed $value = null,
        public array $candidates = [],
    ) {
        if ($status === SemanticStatus::Ok && $value === null) {
            throw new LogicException('A successful semantic result must contain a value.');
        }

        if ($status === SemanticStatus::Ambiguous && count($candidates) < 2) {
            throw new LogicException('An ambiguous semantic result must contain at least two candidates.');
        }
    }

    /**
     * @template T
     * @param T $value
     * @return self<T>
     */
    public static function ok(mixed $value): self
    {
        return new self(SemanticStatus::Ok, $value);
    }

    /**
     * @template T
     * @param list<T> $candidates
     * @return self<T>
     */
    public static function ambiguous(array $candidates): self
    {
        return new self(SemanticStatus::Ambiguous, candidates: $candidates);
    }

    /** @return self<null> */
    public static function notFound(): self
    {
        return new self(SemanticStatus::NotFound);
    }

    /** @return self<null> */
    public static function invalidInput(): self
    {
        return new self(SemanticStatus::InvalidInput);
    }

    /** @return self<null> */
    public static function unsupported(): self
    {
        return new self(SemanticStatus::Unsupported);
    }

    /** @return self<null> */
    public static function parseError(): self
    {
        return new self(SemanticStatus::ParseError);
    }

    /** @return self<null> */
    public static function outsideWorkspace(): self
    {
        return new self(SemanticStatus::OutsideWorkspace);
    }
}
