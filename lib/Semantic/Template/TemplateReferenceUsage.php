<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Template;

/**
 * Transport-independent source range of one static template reference.
 */
final readonly class TemplateReferenceUsage
{
    public function __construct(
        public string $engine,
        public string $name,
        public string $sourceFile,
        public int $contentStart,
        public int $contentEnd,
    ) {
    }
}
