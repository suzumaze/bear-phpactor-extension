<?php

namespace Vendor\App\Module;

class EmptyModule extends \Ray\Di\AbstractModule
{
    protected function configure(): void
    {
        $this->override(new \BEAR\Resource\Module\JsonSchemaModule());
    }
}
