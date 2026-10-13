<?php

declare(strict_types=1);

namespace Acme\Skeleton;

final class Bootstrap
{
    public function __invoke(string $context, array $globals, array $server): int
    {
        $app = Injector::getInstance($context);

        return 0;
    }

    public function boot(string $context): void
    {
        Injector::getInstance($context);
    }

    public function log(string $context): void
    {
        error_log($context);
    }
}
