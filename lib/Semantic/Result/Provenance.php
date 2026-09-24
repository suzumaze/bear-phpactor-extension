<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Result;

use LogicException;

/**
 * One transport-independent piece of evidence behind a semantic result.
 *
 * File paths are workspace-relative by construction. Byte ranges are optional
 * because some facts are derived from a whole file rather than one token.
 */
final readonly class Provenance
{
    public const SOURCE_DERIVED = 'derived';
    public const SOURCE_FILE = 'file';

    public function __construct(
        public string $source,
        public ?string $path,
        public Freshness $freshness,
        public ?int $byteStart = null,
        public ?int $byteEnd = null,
    ) {
        if (!in_array($source, [self::SOURCE_FILE, self::SOURCE_DERIVED], true)) {
            throw new LogicException('A provenance source must be file or derived.');
        }
        if ($source === self::SOURCE_FILE && !$this->isSafeRelativePath($path)) {
            throw new LogicException('File provenance must use a workspace-relative path.');
        }
        if ($source === self::SOURCE_DERIVED && $path !== null) {
            throw new LogicException('Derived provenance must not invent a file path.');
        }
        if (($byteStart === null) !== ($byteEnd === null)) {
            throw new LogicException('A provenance byte range requires both boundaries.');
        }
        if ($byteStart !== null && ($byteStart < 0 || $byteEnd < $byteStart)) {
            throw new LogicException('A provenance byte range must be ordered and non-negative.');
        }
    }

    public static function savedFile(
        string $relativePath,
        ?int $byteStart = null,
        ?int $byteEnd = null,
    ): self {
        return new self(self::SOURCE_FILE, $relativePath, Freshness::Saved, $byteStart, $byteEnd);
    }

    public static function bufferFile(
        string $relativePath,
        ?int $byteStart = null,
        ?int $byteEnd = null,
    ): self {
        return new self(self::SOURCE_FILE, $relativePath, Freshness::Buffer, $byteStart, $byteEnd);
    }

    public static function derived(Freshness $freshness = Freshness::Saved): self
    {
        return new self(self::SOURCE_DERIVED, null, $freshness);
    }

    public function sortKey(): string
    {
        return implode("\0", [
            $this->source,
            $this->path ?? '',
            $this->freshness->value,
            $this->byteStart === null ? '' : (string) $this->byteStart,
            $this->byteEnd === null ? '' : (string) $this->byteEnd,
        ]);
    }

    private function isSafeRelativePath(?string $path): bool
    {
        if (
            $path === null
            || $path === ''
            || str_starts_with($path, '/')
            || preg_match('/^[a-z]:\//i', $path) === 1
            || str_contains($path, "\0")
            || str_contains($path, '\\')
        ) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
