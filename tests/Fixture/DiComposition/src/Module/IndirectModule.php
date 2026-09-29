<?php

namespace Acme\Shop\Module;

use Acme\Shop\Resource\App\Advice;
use Ray\Di\AbstractModule;

class IndirectModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bind(Advice::class)->toProvider('Acme\\Shop\\UnknownProvider');
    }
}
