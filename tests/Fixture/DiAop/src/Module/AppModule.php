<?php

declare(strict_types=1);

namespace Acme\DiAop\Module;

use Acme\DiAop\Annotation\Transactional;
use Acme\DiAop\Interceptor\AuditInterceptor;
use Acme\DiAop\Interceptor\TraceInterceptor;
use Acme\DiAop\Service\Clock;
use Acme\DiAop\Service\ClockInterface;
use Acme\DiAop\Service\DynamicService;
use Ray\Di\AbstractModule as Module;

final class AppModule extends Module
{
    protected function configure(): void
    {
        $this->bind(ClockInterface::class)->to(Clock::class);
        $this->bind($dynamicInterface)->to(DynamicService::class);
        $this->bind(ClockInterface::class)->annotatedWith('primary')->to(Clock::class);

        $this->bindInterceptor(
            $this->matcher->any(),
            $this->matcher->logicalOr(
                $this->matcher->annotatedWith(Transactional::class),
                $this->matcher->startsWith('on'),
            ),
            [AuditInterceptor::class],
        );
        $this->bindPriorityInterceptor(
            methodMatcher: $this->matcher->logicalNot($this->matcher->startsWith('skip')),
            interceptors: [TraceInterceptor::class],
            classMatcher: $this->matcher->subclassesOf(ClockInterface::class),
        );
        $this->bindInterceptor(
            $classMatcher,
            $this->matcher->any(),
            [$dynamicInterceptor],
        );
    }
}
