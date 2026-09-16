<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

/**
 * A supported class- or Resource-method attribute with bounded arguments.
 */
final readonly class ResourceAttributeFact
{
    /**
     * @param 'class'|'method'                     $target
     * @param list<ResourceAttributeArgumentFact> $arguments
     */
    public function __construct(
        public string $target,
        public ?string $methodName,
        public string $name,
        public string $fqn,
        public array $arguments,
        public int $byteStart,
        public int $byteEnd,
    ) {
    }
}
