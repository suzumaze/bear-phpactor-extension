<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Schema;

/**
 * A resolved Schema identity and every explicit source reference found for it.
 */
final readonly class SchemaReferences
{
    /** @param list<SchemaReference> $references */
    public function __construct(
        public SchemaResolution $schema,
        public array $references,
    ) {
    }
}
