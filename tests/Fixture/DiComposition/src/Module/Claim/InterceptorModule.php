<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Ray\Di\AbstractModule;
use Acme\Shop\Service\AuditInterface;
use Acme\Shop\Service\PriorityInterceptor;
use Acme\Shop\Service\TraceInterceptor;

final class InterceptorModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bindInterceptor(
            $this->matcher->any(),
            $this->matcher->any(),
            [TraceInterceptor::class, AuditInterface::class],
        );
        $this->bindPriorityInterceptor(
            $this->matcher->any(),
            $this->matcher->any(),
            [PriorityInterceptor::class],
        );
    }
}
