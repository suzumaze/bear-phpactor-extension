<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Acme\Shop\Service\AuditInterface;
use Acme\Shop\Service\FirstThing;
use Acme\Shop\Service\SecondThing;
use Ray\Di\AbstractModule;

final class EnvBranchProviderModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bind(AuditInterface::class)->toProvider(getenv('ACME_AUDIT') ? FirstThing::class : SecondThing::class);
    }
}
