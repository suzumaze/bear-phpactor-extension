<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Sql;

/**
 * A resolved SQL identity and every static source reference found for it.
 */
final readonly class SqlReferences
{
    /**
     * @param list<SqlReference> $references
     */
    public function __construct(
        public SqlResolution $sql,
        public array $references,
    ) {
    }
}
