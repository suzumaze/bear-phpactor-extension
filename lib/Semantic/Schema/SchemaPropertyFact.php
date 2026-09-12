<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Schema;

final readonly class SchemaPropertyFact
{
    /** @param list<string> $types */
    public function __construct(
        public string $name,
        public bool $required,
        public array $types,
    ) {
    }
}
