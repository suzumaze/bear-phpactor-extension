<?php

declare(strict_types=1);

namespace Acme\Factory;

use Acme\Shop\Module\Claim\FeatureModule;
use Acme\Shop\Module\Claim\OtherFeatureModule;

final class VendorModuleFactory
{
    public function choose()
    {
        if (getenv('FEATURE')) {
            return new FeatureModule();
        }

        return new OtherFeatureModule();
    }
}
