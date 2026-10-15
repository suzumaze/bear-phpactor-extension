<?php

namespace Vendor\App\Module;

use Ray\Di\AbstractModule;

class AppModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bindInterceptor($this->matcher->any(), $this->matcher->any(), [
            \Vendor\App\Interceptor\Trace::class,
            \Vendor\App\Interceptor\Trace::class,
            \Vendor\App\Interceptor\Trace::class,
            \Vendor\App\Interceptor\Trace::class,
            \Vendor\App\Interceptor\Trace::class,
            \Vendor\App\Interceptor\Trace::class,
            \Vendor\App\Interceptor\Trace::class,
            \Vendor\App\Interceptor\Trace::class,
            \Vendor\App\Interceptor\Trace::class,
        ]);
        $this->bindInterceptor($this->matcher->any(), $this->matcher->custom(), $unknown);
    }
}
