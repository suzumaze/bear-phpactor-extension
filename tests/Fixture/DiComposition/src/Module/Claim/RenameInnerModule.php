<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Ray\Di\AbstractModule;
use Acme\Shop\Service\FirstThing;
use Acme\Shop\Service\ThingInterface;

final class RenameInnerModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bind(ThingInterface::class)->annotatedWith('original')->to(FirstThing::class);
    }
}
