<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Ray\Di\AbstractModule;

final class EnvValueModule extends AbstractModule
{
    protected function configure(): void
    {
        $dsn = getenv('ACME_DSN');
        $this->bind()->annotatedWith('dsn')->toInstance(is_string($dsn) ? $dsn : '');
        $this->bind()->annotatedWith('raw_dsn')->toInstance($dsn);
    }
}
