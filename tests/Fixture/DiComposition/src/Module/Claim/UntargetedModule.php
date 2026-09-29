<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Ray\Di\AbstractModule;
use Ray\Di\Scope;
use Acme\Shop\Service\AbstractThing;
use Acme\Shop\Service\BoundService;
use Acme\Shop\Service\ConcreteService;
use Acme\Shop\Service\FirstThing;
use Acme\Shop\Service\NamedService;
use Acme\Shop\Service\SecondThing;
use Acme\Shop\Service\SingletonService;
use Acme\Shop\Service\ThingInterface;

final class UntargetedModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bind(ConcreteService::class);
        $this->bind(NamedService::class)->annotatedWith('named');
        $this->bind(SingletonService::class)->in(Scope::SINGLETON);
        $this->bind(AbstractThing::class);
        $this->bind(ThingInterface::class);
        $this->bind(BoundService::class)->to(SecondThing::class);
        $this->bind(BoundService::class)->annotatedWith('late');
        $this->bind(ConcreteService::class)->annotatedWith('qualified')->to(FirstThing::class);
    }
}
