<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Project;

/**
 * Bounded project-wide contract adoption facts.
 */
final readonly class ContractCoverage
{
    /**
     * @param list<ContractCoverageItem> $items
     * @param array{
     *     methods:int,
     *     coveredMethods:int,
     *     surfaces:array<string,array<string,int>>,
     *     schemes:array<string,array{methods:int,coveredMethods:int,surfaces:array<string,array<string,int>>}>
     * } $summary
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $matchingTotal,
        public int $offset,
        public bool $truncated,
        public bool $gapsOnly,
        public ?string $scheme,
        public int $scannedResources,
        public int $analyzedResources,
        public bool $resourceScanTruncated,
        public array $summary,
    ) {
    }
}
