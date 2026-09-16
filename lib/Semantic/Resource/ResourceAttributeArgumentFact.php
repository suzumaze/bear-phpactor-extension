<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

/**
 * One bounded static argument, or an explicit dynamic argument marker.
 */
final readonly class ResourceAttributeArgumentFact
{
    /** @param 'string'|'number'|'boolean'|'null'|'dynamic' $valueType */
    public function __construct(
        public ?string $name,
        public string $valueType,
        public string|bool|null $value,
    ) {
    }
}
