<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;

/**
 * The transport-independent identity of one resolved BEAR resource.
 */
final readonly class ResourceResolution
{
    public function __construct(
        public ResourceUri $uri,
        public string $file,
        public string $fqn,
    ) {
    }

    /**
     * Compatibility shape used by the pre-semantic resolver API.
     *
     * @return array{file: string, fqn: string}
     */
    public function legacyTarget(): array
    {
        return ['file' => $this->file, 'fqn' => $this->fqn];
    }
}
