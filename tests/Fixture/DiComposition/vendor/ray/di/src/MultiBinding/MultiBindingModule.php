<?php

declare(strict_types=1);

namespace Ray\Di\MultiBinding;

use Ray\Di\AbstractModule;

final class MultiBindingModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bind(MultiBindings::class);
        $this->bind(Map::class)->toProvider(MapProvider::class);
    }
}
