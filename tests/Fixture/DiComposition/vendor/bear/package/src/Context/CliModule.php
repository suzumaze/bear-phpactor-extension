<?php

declare(strict_types=1);

namespace BEAR\Package\Context;

use Ray\Di\AbstractModule;

/**
 * Must never be used when the application has its own CliModule.
 */
final class CliModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bind(CliMarkerInterface::class)->to(CliMarker::class);
    }
}
