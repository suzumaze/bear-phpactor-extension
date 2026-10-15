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
        public string $resolvedVia = 'direct',
    ) {
    }

    /** Class-convention identity, not a claim about runtime routing or rewriting. */
    public function canonicalUri(): ?string
    {
        $marker = '\\Resource\\' . ucfirst($this->uri->scheme()) . '\\';
        $position = strrpos($this->fqn, $marker);
        if ($position === false) {
            return null;
        }
        $path = substr($this->fqn, $position + strlen($marker));

        return $this->uri->scheme() . '://' . $this->uri->host() . '/'
            . implode('/', array_map(lcfirst(...), explode('\\', $path)));
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
