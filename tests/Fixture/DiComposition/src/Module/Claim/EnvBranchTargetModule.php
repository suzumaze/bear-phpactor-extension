<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Acme\Shop\Service\FirstThing;
use Acme\Shop\Service\SecondThing;
use Acme\Shop\Service\ThingInterface;
use Ray\Di\AbstractModule;

final class EnvBranchTargetModule extends AbstractModule
{
    protected function configure(): void
    {
        $choice = strtolower(trim((string) getenv('ACME_THING')));
        $this->bind(ThingInterface::class)->to(
            $choice === 'second' ? SecondThing::class : FirstThing::class,
        );
    }
}
