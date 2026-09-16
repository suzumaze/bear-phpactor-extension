<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Contract;

/**
 * Presence-only comparison. No type or semantic equivalence is implied.
 */
final readonly class ContractNameComparison
{
    /**
     * @param list<'resource'|'schema'|'alps'> $compared
     * @param list<string>                     $common
     * @param list<string>                     $onlyInResource
     * @param list<string>                     $onlyInSchema
     * @param list<string>                     $onlyInAlps
     * @param list<ContractNamePresence>       $presence
     */
    public function __construct(
        public array $compared,
        public array $common,
        public array $onlyInResource,
        public array $onlyInSchema,
        public array $onlyInAlps,
        public array $presence,
    ) {
    }
}
