<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Project;

use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;

/**
 * One evidence-backed problem found while inspecting saved project sources.
 */
final readonly class ProjectDiagnostic
{
    /** @param array<string,mixed> $details */
    public function __construct(
        public string $code,
        public SemanticStatus $status,
        public string $subject,
        public string $path,
        public ?int $byteStart = null,
        public ?int $byteEnd = null,
        public array $details = [],
    ) {
    }
}
