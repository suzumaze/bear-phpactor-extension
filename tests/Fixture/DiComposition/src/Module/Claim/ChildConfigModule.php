<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Acme\Shop\Service\ClockInterface;
use Acme\Shop\Service\SystemClock;

final class ChildConfigModule extends ParentConfigModule
{
    protected function configure(): void
    {
        parent::configure();
        $this->bind(ClockInterface::class)->to(SystemClock::class);
    }
}
