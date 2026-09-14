<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Project;

final readonly class ProjectCompatibilityIssue
{
    /** @param array<string,string> $packages */
    public function __construct(
        public string $code,
        public array $packages,
    ) {
    }
}
