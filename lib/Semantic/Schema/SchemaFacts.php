<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Schema;

final readonly class SchemaFacts
{
    /**
     * @param list<string>             $types
     * @param list<SchemaPropertyFact> $properties
     */
    public function __construct(
        public SchemaResolution $schema,
        public bool $available,
        public array $types = [],
        public array $properties = [],
    ) {
    }

    public static function unavailable(SchemaResolution $schema): self
    {
        return new self($schema, false);
    }
}
