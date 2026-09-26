<?php

declare(strict_types=1);

namespace Acme\Shop\Module;

use Acme\Shop\Service\ClockInterface;
use Acme\Shop\Service\FileLogger;
use Acme\Shop\Service\LegacyApp;
use Acme\Shop\Service\SystemClock;
use BEAR\Package\AbstractAppModule;
use BEAR\Sunday\Extension\Application\AppInterface;
use Psr\Log\LoggerInterface;

final class AppModule extends AbstractAppModule
{
    protected function configure(): void
    {
        $this->bind(LoggerInterface::class)->to(FileLogger::class);
        $this->bind(ClockInterface::class)->to(SystemClock::class);
        $this->bind(AppInterface::class)->to(LegacyApp::class);
    }
}
