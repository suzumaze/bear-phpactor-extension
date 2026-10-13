<?php

declare(strict_types=1);

use BEAR\Package\Injector;

throw new RuntimeException('Entry points must never be executed by discovery.');

$reassigning = static function (string $context): void {
    $context = 'wrong-app';
    Injector::getInstance('Acme\\Skeleton', $context);
};
$reassigning('reassigned-app');

$byReference = static function (string &$context): void {
    Injector::getInstance('Acme\\Skeleton', $context);
};
$byReference('by-reference-app');

$neverCalled = static function (string $context): void {
    Injector::getInstance('Acme\\Skeleton', $context);
};

$assignedTwice = static function (string $context): void {
    Injector::getInstance('Acme\\Skeleton', $context);
};
$assignedTwice = static function (string $context): void {
};
$assignedTwice('assigned-twice-app');

$forwardsInAnotherScope = static function (string $context): void {
    Injector::getInstance('Acme\\Skeleton', $context);
};
$caller = static function () use ($forwardsInAnotherScope): void {
    $forwardsInAnotherScope('another-scope-app');
};

(require $dynamicPath)('dynamic-require-app');
(require __DIR__ . '/' . $name . '.php')('dynamic-name-app');
(require dirname(__DIR__, 2) . '/ContextForwardersOutside/bootstrap.php')('outside-app');

$globalizing = static function (string $context): void {
    global $context;
    Injector::getInstance('Acme\\Skeleton', $context);
};
$globalizing('global-app');

$staticizing = static function (string $context): void {
    static $context;
    Injector::getInstance('Acme\\Skeleton', $context);
};
$staticizing('static-variable-app');

$catching = static function (string $context): void {
    try {
        run();
    } catch (RuntimeException $context) {
        Injector::getInstance('Acme\\Skeleton', $context);
    }
};
$catching('catch-app');

$rebound = static function (string $context): void {
    Injector::getInstance('Acme\\Skeleton', $context);
};
foreach ([] as $rebound) {
}
$rebound('foreach-rebound-app');

(require dirname(__DIR__))('directory-app');
