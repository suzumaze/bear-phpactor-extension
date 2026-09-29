<?php

namespace Acme\Shop\Module;

use Acme\Shop\Annotation;
use Acme\Shop\Interceptor;
use Ray\Di\AbstractModule;

class NonmatchModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bindInterceptor(
            $this->matcher->startsWith('NeverMatches'),
            $this->matcher->annotatedWith(Annotation\First::class),
            [Interceptor\Old::class],
        );
    }
}
