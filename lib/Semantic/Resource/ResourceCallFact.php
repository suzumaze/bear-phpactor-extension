<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;

/**
 * One direct, static call through a Resource client in saved PHP source.
 */
final readonly class ResourceCallFact
{
    public function __construct(
        public string $call,
        public ResourceUri $targetUri,
        public ?string $sourceMethod,
        public ?string $targetMethod,
        public string $sourceFile,
        public int $contentStart,
        public int $contentEnd,
    ) {
    }
}
