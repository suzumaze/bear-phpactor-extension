<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di\Composition;

/**
 * Abstract values of the module interpreter.
 *
 * Known scalars and arrays are plain PHP values; everything the saved source cannot
 * fix is an {@see UnknownValue}, which may still carry its `gettype()` name.
 */
final class UnknownValue
{
    public function __construct(
        public readonly ?string $type = null,
        public readonly bool $reported = false,
        /** gettype() name of the elements, when an unknown array's elements are typed */
        public readonly ?string $elementType = null,
    ) {
    }
}
