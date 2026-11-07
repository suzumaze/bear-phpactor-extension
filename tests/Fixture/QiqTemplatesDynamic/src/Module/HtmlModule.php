<?php

namespace Vendor\App\Module;

use BEAR\QiqModule\QiqModule;
use Ray\Di\AbstractModule;

class HtmlModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->install(new QiqModule((string) getenv('TEMPLATE_DIR')));
    }
}
