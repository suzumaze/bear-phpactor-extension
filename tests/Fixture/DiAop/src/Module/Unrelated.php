<?php

declare(strict_types=1);

namespace Acme\DiAop\Module;

use Acme\DiAop\Service\Clock;
use Acme\DiAop\Service\ClockInterface;

final class Unrelated
{
    public function configure(): void
    {
        $this->bind(ClockInterface::class)->to(Clock::class);
    }
}
