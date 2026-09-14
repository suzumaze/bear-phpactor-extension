<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Schema;

/**
 * Transport-independent source range of one explicit JsonSchema reference.
 */
final readonly class SchemaReference
{
    public function __construct(
        public string $fileName,
        public string $kind,
        public string $sourceFile,
        public int $contentStart,
        public int $contentEnd,
    ) {
    }
}
