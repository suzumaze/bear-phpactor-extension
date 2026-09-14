<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

final readonly class ResourceMethodFact
{
    /** @param list<ResourceParameterFact> $parameters */
    public function __construct(
        public string $name,
        public array $parameters,
    ) {
    }
}
