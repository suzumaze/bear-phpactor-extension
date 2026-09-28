<?php

declare(strict_types=1);

namespace Acme\Shop\Module;

use Acme\Shop\Service\SecondThing;
use Acme\Shop\Service\ThingInterface;
use Ray\Di\AbstractModule;

final class OracleChildModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bind(ThingInterface::class)->to(SecondThing::class);
    }
}
