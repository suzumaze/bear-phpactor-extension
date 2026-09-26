<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di\Composition;

/**
 * An object whose class is known; module objects additionally own a container.
 */
final class ObjectValue
{
    public ?EmulatedContainer $container = null;

    public ?ObjectValue $lastModule = null;

    /** @param array<string, mixed> $properties */
    public function __construct(public readonly string $class, public array $properties = [])
    {
    }
}
