<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Ray\Di\AbstractModule;
use Acme\Shop\Service\FeatureThing;
use Acme\Shop\Service\ThingInterface;

final class FeatureModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bind(ThingInterface::class)->to(FeatureThing::class);
    }
}
