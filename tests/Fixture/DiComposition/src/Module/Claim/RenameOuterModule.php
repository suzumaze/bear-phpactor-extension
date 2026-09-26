<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Ray\Di\AbstractModule;
use Acme\Shop\Service\ThingInterface;

final class RenameOuterModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->rename(ThingInterface::class, 'renamed', 'original');
    }
}
