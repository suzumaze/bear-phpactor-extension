<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Project;

/**
 * A bounded project-wide diagnostic result over saved sources.
 */
final readonly class ProjectDiagnostics
{
    /**
     * @param list<ProjectDiagnostic> $items
     * @param list<string> $skippedChecks
     */
    public function __construct(
        public array $items,
        public int $total,
        public bool $truncated,
        public int $scannedFiles,
        public int $scannedResources,
        public bool $resourceScanTruncated,
        public array $skippedChecks,
    ) {
    }
}
