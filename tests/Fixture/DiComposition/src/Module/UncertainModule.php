<?php

declare(strict_types=1);

namespace Acme\Shop\Module;

use Acme\Shop\Service\ClockInterface;
use Ray\Di\AbstractModule;

final class UncertainModule extends AbstractModule
{
    protected function configure(): void
    {
        if (getenv('CHOOSE_CLOCK')) {
            $this->bind(ClockInterface::class)->to('ClockChosenAtRuntime');
        }
    }
}
