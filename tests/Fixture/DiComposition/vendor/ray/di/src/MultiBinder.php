<?php

declare(strict_types=1);

namespace Ray\Di;

final class MultiBinder
{
    private ?string $key = null;

    private function __construct(private AbstractModule $module, private string $interface)
    {
    }

    public static function newInstance(AbstractModule $module, string $interface): self
    {
        return new self($module, $interface);
    }

    public function addBinding(?string $key = null): self
    {
        $this->key = $key;

        return $this;
    }

    public function to(string $class): void
    {
    }
}
