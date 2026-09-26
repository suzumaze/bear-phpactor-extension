<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Ray\Di\AbstractModule;

final class EnvTernaryModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->install(getenv('ACME_FEATURE') ? new FeatureModule() : new OtherFeatureModule());
    }
}
