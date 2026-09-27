<?php

declare(strict_types=1);

use BEAR\Package\Bootstrap as Start;
use BEAR\Package\Injector;
use Unrelated\Injector as OtherInjector;

throw new RuntimeException('Entry points must never be executed by discovery.');
(new Start())('prod-html-app', dirname(__DIR__));
Injector::getInstance(PHP_SAPI === 'cli' ? 'cli-app' : 'dev-html-app', 'Acme\\Shop');
Injector::getInstance(context: $context ?? 'prod-app', appName: 'Acme\\Shop');
Injector::getInstance('prod-' . $suffix, 'Acme\\Shop');
OtherInjector::getInstance('not-a-bear-context');
// Injector::getInstance('not-a-call');
