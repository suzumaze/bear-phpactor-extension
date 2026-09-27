<?php

namespace Acme\Shop\Module;

use Ray\Di\AbstractModule;
use Acme\Shop\Annotation;
use Acme\Shop\Interceptor;

class AdviceModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bindInterceptor(
            $this->matcher->any(),
            $this->matcher->annotatedWith(Annotation\First::class),
            [Interceptor\Old::class],
        );
        $this->bindInterceptor(
            $this->matcher->any(),
            $this->matcher->annotatedWith(Annotation\First::class),
            [Interceptor\First::class],
        );
        $this->bindInterceptor(
            $this->matcher->any(),
            $this->matcher->annotatedWith(Annotation\Second::class),
            [Interceptor\Second::class],
        );
        $this->bindInterceptor(
            $this->matcher->startsWith('Acme\\Shop\\Resource'),
            $this->matcher->startsWith('on'),
            [Interceptor\Tail::class, Interceptor\Tail::class],
        );
        $this->bindPriorityInterceptor(
            $this->matcher->any(),
            $this->matcher->startsWith('onGet'),
            [Interceptor\Priority::class],
        );
    }
}
