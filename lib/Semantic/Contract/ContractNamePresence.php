<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Contract;

/**
 * Sources in which one exact name was observed.
 */
final readonly class ContractNamePresence
{
    /** @param list<'resource'|'schema'|'alps'> $sources */
    public function __construct(
        public string $name,
        public array $sources,
    ) {
    }
}
