<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

final readonly class ResourceParameterFact
{
    public function __construct(
        public string $name,
        public ?string $type,
    ) {
    }
}
