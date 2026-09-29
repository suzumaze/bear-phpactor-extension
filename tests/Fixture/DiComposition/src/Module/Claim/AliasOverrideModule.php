<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Acme\Shop\Service\ThingInterface;
use Ray\Di\AbstractModule;

final class AliasOverrideModule extends AbstractModule
{
    protected function configure(): void
    {
        $overriding = new OverridingModule();
        $this->override($overriding);
        $overriding->changeBinding();
    }
}
