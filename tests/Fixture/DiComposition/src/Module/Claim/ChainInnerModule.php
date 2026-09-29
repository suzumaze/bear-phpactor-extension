<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Ray\Di\AbstractModule;
use Acme\Shop\Service\ClockInterface;
use Acme\Shop\Service\SecondThing;
use Acme\Shop\Service\SystemClock;
use Acme\Shop\Service\ThingInterface;

final class ChainInnerModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bind(ThingInterface::class)->to(SecondThing::class);
        $this->bind(ClockInterface::class)->to(SystemClock::class);
    }
}
