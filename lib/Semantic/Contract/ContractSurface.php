<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Contract;

use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;

/**
 * One independently observable name surface in a contract comparison.
 */
final readonly class ContractSurface
{
    /**
     * @param 'resource'|'schema'|'alps' $source
     * @param list<string>               $names
     */
    public function __construct(
        public string $source,
        public SemanticStatus $status,
        public ?string $subject,
        public array $names,
    ) {
    }
}
