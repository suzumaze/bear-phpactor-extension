<?php

declare(strict_types=1);

namespace Acme\DiAopRetainedBind\Module;

use Ray\Di\AbstractModule;

final class RetainedBindModule extends AbstractModule
{
    protected function configure(): void
    {
        // The retained Bind can still receive a qualifier or target in later statements.
        $bind = $this->bind(Clock::class);
        $bind->annotatedWith('later');
        $this->bind(Logger::class)->annotatedWith('direct');
        getenv('FEATURE') && $this->bind(Mailer::class)->to(SmtpMailer::class);
    }
}
