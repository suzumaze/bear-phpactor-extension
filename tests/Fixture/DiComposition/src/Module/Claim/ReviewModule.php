<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

use Ray\Di\AbstractModule;

final class ReviewModule extends AbstractModule
{
    use ReviewTrait;

    protected function configure(): void
    {
    }

    public function shortAnd(): void
    {
        getenv('FEATURE') && $this->bind('Service')->to('Enabled');
    }

    public function shortOr(): void
    {
        getenv('FEATURE') || $this->bind('Service')->to('Fallback');
    }

    public function coalesce(): void
    {
        $this->bind('Service')->to(getenv('TARGET') ?? 'Fallback');
    }

    public function optionalExtension(): void
    {
        if (class_exists(\PDO::class)) {
            $this->bind('Service')->to('PdoService');
        } else {
            $this->bind('Service')->to('Fallback');
        }
    }

    public function missingProjectClass(): void
    {
        if (class_exists('Acme\\Shop\\Module\\MissingModule')) {
            $this->bind('Service')->to('Missing');
        } else {
            $this->bind('Service')->to('Fallback');
        }
    }

    public function installHelper(): void
    {
        $this->install(ModuleFactory::choose());
    }

    public function overrideHelper(): void
    {
        $this->override(ModuleFactory::choose());
    }

    public function helperChoice(): void
    {
        $this->bind('Service')->to(ReviewHelper::pick());
    }

    public function closureChoice(): void
    {
        $bind = function (): void {
            $this->bind('Service')->to('Hidden');
        };
        $bind();
    }

    public function arrowChoice(): void
    {
        $bind = fn () => $this->bind('Service')->to('Hidden');
        $bind();
    }

    public function callbackChoice(): void
    {
        array_map(function (): void {
            $this->bind('Service')->to('Hidden');
        }, [1]);
    }

    public function delayedQualifier(): void
    {
        $bind = $this->bind('Service');
        $bind->annotatedWith('qualified');
        $bind->to('Enabled');
    }

    public function literalQualifier(): void
    {
        $this->bind('Service')->annotatedWith('\\Fx\\Q')->to('Enabled');
    }
}
