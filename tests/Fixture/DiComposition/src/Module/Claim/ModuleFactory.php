<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

final class ModuleFactory
{
    public static function choose()
    {
        return getenv('FEATURE') ? new FeatureModule() : new OtherFeatureModule();
    }
}
