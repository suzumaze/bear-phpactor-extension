<?php

declare(strict_types=1);

use BEAR\Package\Injector;

// docs/ is not a scan root. Only a directory require that widens the scan could reach this.
Injector::getInstance('Acme\\Skeleton', 'docs-app');
