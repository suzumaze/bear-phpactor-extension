<?php

declare(strict_types=1);

namespace Acme\Shop\Module;

use Acme\Shop\Service\FirstThing;
use Acme\Shop\Service\ThingInterface;
use Ray\Di\AbstractModule;

final class OracleInstallModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bind(ThingInterface::class)->to(FirstThing::class);
        $this->install(new OracleChildModule());
    }
}
