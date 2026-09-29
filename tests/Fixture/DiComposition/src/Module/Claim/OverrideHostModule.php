<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Ray\Di\AbstractModule;
use Acme\Shop\Service\ClockInterface;
use Acme\Shop\Service\FileLogger;
use Acme\Shop\Service\FirstThing;
use Acme\Shop\Service\SystemClock;
use Acme\Shop\Service\ThingInterface;
use Psr\Log\LoggerInterface;

final class OverrideHostModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bind(ThingInterface::class)->to(FirstThing::class);
        $this->bind(ClockInterface::class)->to(SystemClock::class);
        $this->override(new OverridingModule());
        $this->bind(LoggerInterface::class)->to(FileLogger::class);
    }
}
