<?php

namespace Vendor\App\Module;

use Ray\Di\AbstractModule;

class LookupModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bindInterceptor(
            $this->matcher->any(),
            $this->matcher->startsWith('never'),
            [\Vendor\App\Interceptor\Unused::class],
        );
    }
}
