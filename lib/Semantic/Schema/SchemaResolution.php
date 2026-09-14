<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Schema;

use Suzumaze\BearPhpactor\Semantic\Resource\ResourceResolution;

/**
 * JSON Schema selected by an explicit attribute or Resource convention.
 */
final readonly class SchemaResolution
{
    public function __construct(
        public string $kind,
        public string $source,
        public ?string $file,
        public ?int $titleOffset,
        public ?ResourceResolution $resource = null,
    ) {
    }
}
