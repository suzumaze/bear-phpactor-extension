<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di\Composition;

/**
 * A pending `bind()` chain, mirroring Ray\Di\Bind until the statement ends.
 */
final class BindValue
{
    public string $name = '';

    public bool $untarget;

    public function __construct(
        public readonly EmulatedContainer $container,
        public readonly string $interface,
        public readonly string $source,
        bool $untarget,
    ) {
        $this->untarget = $untarget;
    }
}
