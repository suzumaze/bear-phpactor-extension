<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Ray\Di\AbstractModule;
use Acme\Shop\Service\ClockInterface;
use Acme\Shop\Service\SystemClock;

final class TypedCheckModule extends AbstractModule
{
    protected function configure(): void
    {
        $port = (string) getenv('ACME_PORT');
        if (is_string($port)) {
            $this->bind(ClockInterface::class)->to(SystemClock::class);
        }
    }
}
