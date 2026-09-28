<?php

declare(strict_types=1);

namespace Acme\Shop;

use BEAR\Package\Injector as PackageInjector;

final class UnsafeForwardingInjector
{
    public static function getInstance(string $context): object
    {
        return PackageInjector::getInstance($context = 'wrong-app', $context, dirname(__DIR__));
    }
}
