<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Schema;

/**
 * Parsed facts that depend only on one saved JSON Schema document.
 */
final readonly class SchemaDocumentFacts
{
    /**
     * @param list<string>             $types
     * @param list<SchemaPropertyFact> $properties
     */
    public function __construct(
        public array $types,
        public array $properties,
    ) {
    }
}
