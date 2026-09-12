<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Project;

final readonly class ProjectPsr4Root
{
    public function __construct(
        public string $namespace,
        public string $path,
    ) {
    }
}
