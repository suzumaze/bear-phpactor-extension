<?php

namespace Vendor\App\Module;

class AppModule extends \Ray\Di\AbstractModule
{
    protected function configure(): void
    {
        $root = dirname(__DIR__, 2);
        $this->bind()->annotatedWith('json_schema_dir')->toInstance($root . '/schema');
        $this->bind()->annotatedWith('json_validate_dir')->toInstance($root . '/schema');
    }
}
