<?php

namespace Vendor\App\Module;

class ProdModule extends \Ray\Di\AbstractModule
{
    protected function configure(): void
    {
        $this->bind()->annotatedWith('json_schema_dir')->toInstance(dirname(__DIR__, 2) . '/config/production');
    }
}
