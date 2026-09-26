<?php

declare(strict_types=1);

namespace Ray\Di;

use Ray\Aop\MethodInvocation;

final class AssistedModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->install(new AssistedInjectModule());
        $this->bind(MethodInvocation::class)->toProvider(MethodInvocationProvider::class)->in(Scope::SINGLETON);
        $this->bind(MethodInvocationProvider::class)->in(Scope::SINGLETON);
    }
}
