<?php

declare(strict_types=1);

use BEAR\Package\Compiler\Bootstrap as Start;
use BEAR\Package\Injector;
use Unrelated\Injector as OtherInjector;

throw new RuntimeException('Entry points must never be executed by discovery.');
(new Start())('Acme\\Shop', 'prod-html-app', [], []);
Injector::getInstance('Acme\\Shop', PHP_SAPI === 'cli' ? 'cli-app' : 'dev-html-app', dirname(__DIR__));
Injector::getInstance(context: $context ?? 'prod-app', appName: 'Acme\\Shop', appDir: dirname(__DIR__));
Injector::getOverrideInstance('Acme\\Shop', 'override-app', dirname(__DIR__), $module);
Injector::getInstance('Acme\\Shop', 'prod-' . $suffix, dirname(__DIR__));
Injector::getInstance(...$injectedArguments);
OtherInjector::getInstance('not-a-bear-context');
\Acme\Shop\ForwardingInjector::getInstance('forwarded-app');
\Acme\Shop\UnsafeForwardingInjector::getInstance('unsafe-wrapper-app');
\Acme\Shop\EmptyForwarder::getInstance('empty-wrapper-app');
// Injector::getInstance('not-a-call');
