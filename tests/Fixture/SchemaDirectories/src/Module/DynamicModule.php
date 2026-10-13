<?php

namespace Vendor\App\Module;

class DynamicModule extends \Ray\Di\AbstractModule
{
    protected function configure(): void
    {
        $this->bind()->annotatedWith('json_schema_dir')->toInstance(getenv('SCHEMA_DIR'));
    }
}
