<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

use Microsoft\PhpParser\Node\Statement\ClassDeclaration;

/**
 * One directly declared Ray.Di module and its bounded saved source.
 */
final readonly class RayModuleSource
{
    public function __construct(
        public string $module,
        public string $path,
        public string $contents,
        public ClassDeclaration $declaration,
    ) {
    }
}
