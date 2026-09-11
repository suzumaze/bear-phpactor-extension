<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Sql;

/**
 * Transport-independent result of resolving a BEAR SQL query identifier.
 */
final readonly class SqlResolution
{
    public function __construct(
        public string $queryId,
        public string $file,
    ) {
    }
}
