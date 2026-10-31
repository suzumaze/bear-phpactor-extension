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
        /** @var list<string> names read by getenv() that flow into this value; other runtime inputs are not listed */
        public readonly array $environment = [],
        /**
         * Both arms of a ternary whose condition is unknown but whose arms are known strings.
         *
         * @var list<array{when: 'condition_true'|'condition_false', value: string}>|null
         */
        public readonly ?array $branches = null,
        /** @var array{path: string, line: int, environment: list<string>}|null */
        public readonly ?array $condition = null,
    ) {
    }
}
