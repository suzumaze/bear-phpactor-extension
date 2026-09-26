<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Ray\Di\AbstractModule;
use Ray\Di\MultiBinder;
use Acme\Shop\Service\FirstThing;
use Acme\Shop\Service\ThingInterface;

final class MultiHostModule extends AbstractModule
{
    protected function configure(): void
    {
        MultiBinder::newInstance($this, ThingInterface::class)->addBinding('first')->to(FirstThing::class);
        $this->install(new MultiChildModule());
    }
}
