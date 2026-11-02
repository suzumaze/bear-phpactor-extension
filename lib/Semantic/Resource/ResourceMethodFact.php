<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

final readonly class ResourceMethodFact
{
    /**
     * @param list<ResourceParameterFact> $parameters
     * @param list<array{line: int, earlyExit: bool, rels: list<string>}> $embedReplacements
     */
    public function __construct(
        public string $name,
        public array $parameters,
        public ResourceResponseShape $responseShape,
        /** full `$this->body = ...` assignments in a method whose `#[Embed]` keys they replace */
        public array $embedReplacements = [],
    ) {
    }
}
