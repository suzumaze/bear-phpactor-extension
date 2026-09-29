<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Ray\Di\AbstractModule;
use Acme\Shop\Service\OverridingThing;
use Acme\Shop\Service\ThingInterface;

final class OverridingModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bind(ThingInterface::class)->to(OverridingThing::class);
    }

    public function changeBinding(): void
    {
        $this->bind(ThingInterface::class)->to('ChangedAfterOverride');
    }
}
