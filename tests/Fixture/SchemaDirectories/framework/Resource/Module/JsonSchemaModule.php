<?php

namespace BEAR\Resource\Module;

use Ray\Di\AbstractModule;

class JsonSchemaModule extends AbstractModule
{
    public function __construct(
        private string $jsonSchemaDir = '',
        private string $jsonValidateDir = '',
        ?AbstractModule $module = null,
    ) {
        parent::__construct($module);
    }

    protected function configure(): void
    {
        $this->bind()->annotatedWith('json_schema_dir')->toInstance($this->jsonSchemaDir);
        $this->bind()->annotatedWith('json_validate_dir')->toInstance($this->jsonValidateDir);
    }
}
