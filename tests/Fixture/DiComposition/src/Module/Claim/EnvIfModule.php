<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Ray\Di\AbstractModule;
use Acme\Shop\Service\ClockInterface;
use Acme\Shop\Service\SystemClock;

final class EnvIfModule extends AbstractModule
{
    protected function configure(): void
    {
        if (getenv('ACME_FEATURE')) {
            $this->install(new FeatureModule());
        }
        $this->bind(ClockInterface::class)->to(SystemClock::class);
    }
}
