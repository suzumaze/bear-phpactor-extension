<?php

declare(strict_types=1);

namespace Acme\Shop\Module;

use Acme\Shop\Service\ClockInterface;
use Acme\Shop\Service\FrozenClock;
use BEAR\Package\AbstractAppModule;

final class CliModule extends AbstractAppModule
{
    protected function configure(): void
    {
        $this->bind(ClockInterface::class)->to(FrozenClock::class);
    }
}
