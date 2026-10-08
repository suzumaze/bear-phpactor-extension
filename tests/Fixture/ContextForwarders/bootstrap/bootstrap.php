<?php

declare(strict_types=1);

use BEAR\Package\Injector;

return static function (string $context, array $globals, array $server): int {
    $app = Injector::getInstance('Acme\\Skeleton', $context, dirname(__DIR__));
    if (str_contains($context, 'prod')) {
        $server['HTTPS'] = 'on';
    }

    return 0;
};
