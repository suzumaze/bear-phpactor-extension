<?php

declare(strict_types=1);

use BEAR\Package\Injector;

Injector::getInstance('Acme\\Skeleton', 'batch-app', dirname(__DIR__));
