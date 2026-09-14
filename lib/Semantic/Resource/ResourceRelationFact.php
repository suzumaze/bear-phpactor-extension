<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;

final readonly class ResourceRelationFact
{
    public function __construct(
        public string $kind,
        public string $rel,
        public ResourceUri $sourceUri,
        public string $sourceMethod,
        public ResourceUri $targetUri,
        public ?string $targetMethod,
        public string $sourceFile,
        public int $byteOffset,
    ) {
    }
}
