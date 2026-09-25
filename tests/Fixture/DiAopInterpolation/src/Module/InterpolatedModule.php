<?php

declare(strict_types=1);

namespace Acme\DiAopInterpolation\Module;

use Acme\DiAopInterpolation\Interceptor\TraceInterceptor;
use Acme\DiAopInterpolation\Service\Clock;
use Ray\Di\AbstractModule;

final class InterpolatedModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bind()->annotatedWith("db_$env")->toInstance('dsn');
        $this->bind()->annotatedWith("cache_{$suffix}")->toInstance('dsn');
        $this->bind(<<<EOT
Acme\\{$name}
EOT)->to(Clock::class);
        $this->bind()->annotatedWith(<<<'NOW'
nowdoc_$name
NOW)->toInstance('dsn');
        $this->bind()->annotatedWith("plain_name")->toInstance('dsn');
        $this->bind()->annotatedWith('single_$name')->toInstance('dsn');

        $this->bindInterceptor(
            $this->matcher->any(),
            $this->matcher->annotatedWith("Acme\\DiAopInterpolation\\$attribute"),
            [TraceInterceptor::class],
        );
        $this->bindInterceptor(
            $this->matcher->any(),
            $this->matcher->any(),
            ["Acme\\DiAopInterpolation\\{$interceptor}"],
        );
    }
}
