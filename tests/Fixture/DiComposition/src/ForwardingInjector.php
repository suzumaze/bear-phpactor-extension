<?php

declare(strict_types=1);

namespace Acme\Shop;

use BEAR\Package\Injector as PackageInjector;

final class ForwardingInjector
{
    public static function getInstance(string $context): object
    {
        return PackageInjector::getInstance(__NAMESPACE__, $context, dirname(__DIR__));
    }
}
