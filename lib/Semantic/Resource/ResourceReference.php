<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

/**
 * Transport-independent source range of one static Resource reference.
 */
final readonly class ResourceReference
{
    public const KIND_RESOURCE_URI = 'resource_uri';
    public const KIND_ROUTE = 'route';

    public function __construct(
        public string $kind,
        public string $identifier,
        public string $file,
        public int $contentStart,
        public int $contentEnd,
    ) {
    }
}
