<?php

declare(strict_types=1);

use BEAR\Package\Injector;

$compile = static function (string $context): void {
    Injector::getInstance('Acme\\Skeleton', $context, dirname(__DIR__));
};
$compile('prod-compile-app');
$compile('prod-admin-app');

$arrow = static fn (string $context): object => Injector::getInstance('Acme\\Skeleton', $context);
$arrow('arrow-app');
