<?php

namespace Vendor\App\Module;

use BEAR\Package\AbstractAppModule;
use BEAR\Resource\Module\JsonSchemaModule;

class AppModule extends AbstractAppModule
{
    protected function configure(): void
    {
        $root = $this->appMeta->appDir;
        $this->install(new JsonSchemaModule($root . '/config/schema', $root . '/config/input'));
    }
}
