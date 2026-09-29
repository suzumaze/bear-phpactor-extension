<?php

declare(strict_types=1);

namespace Acme\DiAop\Module;

use Acme\DiAop\Interceptor\TestInterceptor;

final class TestModule extends AppModule
{
    protected function configure(): void
    {
        parent::configure();
        $this->bindInterceptor(
            $this->matcher->any(),
            $this->matcher->startsWith('onGet'),
            [TestInterceptor::class],
        );
    }
}
