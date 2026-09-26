<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Ray\Di\AbstractModule;
use Acme\Shop\Service\OtherFeatureThing;
use Acme\Shop\Service\ThingInterface;

final class OtherFeatureModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bind(ThingInterface::class)->to(OtherFeatureThing::class);
    }
}
