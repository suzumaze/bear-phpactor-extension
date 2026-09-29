<?php

namespace Acme\Shop\Module;

use Ray\Di\AbstractModule;
use Acme\Shop\Resource\App\Advice;
use Acme\Shop\Resource\App\Replacement;

class ReplacementModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bind(Advice::class)->to(Replacement::class);
    }
}
