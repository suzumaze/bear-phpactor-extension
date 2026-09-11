<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Workspace;

/**
 * A canonical path proven to be inside a workspace.
 */
final readonly class WorkspacePath
{
    public function __construct(
        public string $absolute,
        public string $relative,
    ) {
    }
}
