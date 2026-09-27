<?php

declare(strict_types=1);

namespace Acme\Shop\Module;

use Acme\Shop\Module\Claim\OverrideHostModule;
use Acme\Shop\Resource\Page\Index;
use BEAR\Package\Provide\Error\NullPage;
use Ray\Di\AbstractModule;

final class InspectModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->install(new OverrideHostModule());
        $this->bind(Index::class)->to(NullPage::class);
        $this->bind()->annotatedWith('api-key')->toInstance('fixture-private-value');
    }
}
