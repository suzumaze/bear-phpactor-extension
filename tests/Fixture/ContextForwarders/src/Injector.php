<?php

declare(strict_types=1);

namespace Acme\Skeleton;

use BEAR\Package\Injector as PackageInjector;

final class Injector
{
    public static function getInstance(string $context): object
    {
        return PackageInjector::getInstance(__NAMESPACE__, $context, dirname(__DIR__));
    }
}
