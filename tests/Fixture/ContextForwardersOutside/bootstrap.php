<?php

declare(strict_types=1);

use BEAR\Package\Injector;

return static function (string $context): void {
    Injector::getInstance('Acme\\Skeleton', $context);
};
