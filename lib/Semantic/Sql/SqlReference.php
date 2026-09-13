<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Sql;

/**
 * Transport-independent source range of one static SQL query reference.
 */
final readonly class SqlReference
{
    public function __construct(
        public string $queryId,
        public string $file,
        public int $contentStart,
        public int $contentEnd,
    ) {
    }
}
