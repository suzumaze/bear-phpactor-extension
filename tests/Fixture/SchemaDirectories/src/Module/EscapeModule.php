<?php

namespace Vendor\App\Module;

class EscapeModule extends \Ray\Di\AbstractModule
{
    protected function configure(): void
    {
        $this->bind()->annotatedWith('json_schema_dir')->toInstance('/outside-workspace');
    }
}
