<?php

declare(strict_types=1);

use Acme\Skeleton\Bootstrap;
use Acme\Skeleton\PlainInvokable;

(new Bootstrap())->boot('html-hal-app');
(new Bootstrap())->log('logged-app');
(new PlainInvokable())('plain-invokable-app');
