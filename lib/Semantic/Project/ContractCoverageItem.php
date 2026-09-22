<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Project;

/**
 * Contract adoption facts for one saved Resource method.
 */
final readonly class ContractCoverageItem
{
    /** @param list<string> $gaps */
    public function __construct(
        public string $uri,
        public string $method,
        public string $path,
        public ContractCoverageSurface $requestSchema,
        public ContractCoverageSurface $responseSchema,
        public ContractCoverageSurface $alps,
        public array $gaps,
    ) {
    }

    public function covered(): bool
    {
        return $this->gaps === [];
    }
}
