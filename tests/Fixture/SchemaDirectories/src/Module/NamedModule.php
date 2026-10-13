<?php

namespace Vendor\App\Module;

class NamedModule extends \Ray\Di\AbstractModule
{
    protected function configure(): void
    {
        $this->override(new \BEAR\Resource\Module\JsonSchemalModule(
            jsonValidateDir: __DIR__ . '/../../config/input',
            jsonSchemaDir: __DIR__ . '/../../config/schema',
        ));
    }
}
