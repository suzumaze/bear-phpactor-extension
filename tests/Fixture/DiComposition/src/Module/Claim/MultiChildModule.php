<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Ray\Di\AbstractModule;
use Ray\Di\MultiBinder;
use Acme\Shop\Service\SecondThing;
use Acme\Shop\Service\ThingInterface;

final class MultiChildModule extends AbstractModule
{
    protected function configure(): void
    {
        MultiBinder::newInstance($this, ThingInterface::class)->addBinding('second')->to(SecondThing::class);
    }
}
