<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Contract;

use Suzumaze\BearPhpactor\Semantic\Resource\ResourceResolution;

final readonly class ContractComparison
{
    /** @param list<ContractSurface> $surfaces */
    public function __construct(
        public ResourceResolution $resource,
        public string $method,
        public string $schemaKind,
        public array $surfaces,
        public ?ContractNameComparison $comparison,
    ) {
    }
}
