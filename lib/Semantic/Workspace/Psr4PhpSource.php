<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Workspace;

/**
 * One bounded, canonical PHP source file discovered below a PSR-4 root.
 */
final readonly class Psr4PhpSource
{
    public function __construct(
        public string $file,
        public string $contents,
    ) {
    }
}
