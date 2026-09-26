<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use LogicException;
use Ray\Di\AbstractModule;
use Acme\Shop\Service\ClockInterface;
use Acme\Shop\Service\SystemClock;

final class ThrowGuardModule extends AbstractModule
{
    protected function configure(): void
    {
        if (getenv('ACME_REQUIRED') === false) {
            throw new LogicException('ACME_REQUIRED is required.');
        }
        $this->bind(ClockInterface::class)->to(SystemClock::class);
    }
}
