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
     * @param list<Provenance> $provenance
     */
    private function __construct(
        public SemanticStatus $status,
        public mixed $value = null,
        public mixed $partial = null,
        public array $candidates = [],
        public ?SemanticError $error = null,
        public array $provenance = [],
    ) {
        if ($status === SemanticStatus::Ok && $value === null) {
            throw new LogicException('A successful semantic result must contain a value.');
        }
        if ($status !== SemanticStatus::Ok && $value !== null) {
            throw new LogicException('A failed semantic result must not contain a successful value.');
        }
        if ($partial !== null && $status !== SemanticStatus::NotFound) {
            throw new LogicException('Only a not-found semantic result may contain a partial value.');
        }

        if ($status === SemanticStatus::Ambiguous && count($candidates) < 2) {
            throw new LogicException('An ambiguous semantic result must contain at least two candidates.');
        }
        if ($status === SemanticStatus::Ok && $error !== null) {
            throw new LogicException('A successful semantic result must not contain an error.');
        }
        if ($status !== SemanticStatus::Ok && $error === null) {
            throw new LogicException('A failed semantic result must contain an error.');
        }
    }

    /**
     * @template T
     * @param T $value
     * @param list<Provenance> $provenance
     * @return self<T>
     */
    public static function ok(mixed $value, array $provenance = []): self
    {
        return new self(
            SemanticStatus::Ok,
            $value,
            provenance: self::normalizeProvenance($provenance),
        );
    }

    /**
     * @template T
     * @param list<T> $candidates
     * @param list<Provenance> $provenance
     * @return self<T>
     */
    public static function ambiguous(
        array $candidates,
        ?SemanticError $error = null,
        array $provenance = [],
    ): self {
        return new self(
            SemanticStatus::Ambiguous,
            candidates: $candidates,
            error: $error ?? SemanticError::fromStatus(SemanticStatus::Ambiguous),
            provenance: self::normalizeProvenance($provenance),
        );
    }

    /**
     * Propagate a non-successful status across typed query boundaries.
     *
     * @return self<null>
     */
    public static function failure(
        SemanticStatus $status,
        ?SemanticError $error = null,
        array $provenance = [],
    ): self {
        if ($status === SemanticStatus::Ok || $status === SemanticStatus::Ambiguous) {
            throw new LogicException(sprintf('Status "%s" requires semantic data.', $status->value));
        }

        return new self(
            $status,
            error: $error ?? SemanticError::fromStatus($status),
            provenance: self::normalizeProvenance($provenance),
        );
    }

    /**
     * Return a not-found query with a useful partial result.
     *
     * @template T
     * @param T $partial
     * @param list<Provenance> $provenance
     * @return self<T>
     */
    public static function notFoundWithPartial(
        mixed $partial,
        ?SemanticError $error = null,
        array $provenance = [],
    ): self {
        if ($partial === null) {
            throw new LogicException('A partial not-found semantic result must contain a partial value.');
        }

        return new self(
            SemanticStatus::NotFound,
            partial: $partial,
            error: $error ?? SemanticError::fromStatus(SemanticStatus::NotFound),
            provenance: self::normalizeProvenance($provenance),
        );
    }

    /** @return self<null> */
    public static function notFound(): self
    {
        return self::failure(SemanticStatus::NotFound);
    }

    /** @return self<null> */
    public static function invalidInput(): self
    {
        return self::failure(SemanticStatus::InvalidInput);
    }

    /** @return self<null> */
    public static function unsupported(): self
    {
        return self::failure(SemanticStatus::Unsupported);
    }

    /** @return self<null> */
    public static function parseError(): self
    {
        return self::failure(SemanticStatus::ParseError);
    }

    /** @return self<null> */
    public static function outsideWorkspace(): self
    {
        return self::failure(SemanticStatus::OutsideWorkspace);
    }

    /** @return self<null> */
    public static function engineUnavailable(): self
    {
        return self::failure(SemanticStatus::EngineUnavailable);
    }

    /** @return self<null> */
    public static function timeout(): self
    {
        return self::failure(SemanticStatus::Timeout);
    }

    /**
     * Preserve the status, value, candidates, and error while adding evidence.
     *
     * @param list<Provenance> $provenance
     * @return self<TValue>
     */
    public function withProvenance(array $provenance): self
    {
        return new self(
            status: $this->status,
            value: $this->value,
            partial: $this->partial,
            candidates: $this->candidates,
            error: $this->error,
            provenance: self::normalizeProvenance([...$this->provenance, ...$provenance]),
        );
    }

    /**
     * @param list<Provenance> $provenance
     * @return list<Provenance>
     */
    private static function normalizeProvenance(array $provenance): array
    {
        $unique = [];
        foreach ($provenance as $evidence) {
            $unique[$evidence->sortKey()] = $evidence;
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }
}
