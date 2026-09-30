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

    public function helperAfterSwitch(): void
    {
        $this->bind('Service')->to(ReviewHelper::pickAfterSwitch());
    }

    public function helperAfterWhile(): void
    {
        $this->bind('Service')->to(ReviewHelper::pickAfterWhile());
    }

    public function helperAfterForeach(): void
    {
        $this->bind('Service')->to(ReviewHelper::pickAfterForeach());
    }

    public function helperAfterTry(): void
    {
        $this->bind('Service')->to(ReviewHelper::pickAfterTry());
    }

    public function vendorFactoryInstall(): void
    {
        $this->install((new \Acme\Factory\VendorModuleFactory())->choose());
    }

    public function arrayCallableChoice(): void
    {
        array_map([$this, 'bindHidden'], [1]);
    }

    public function filterCallableChoice(): void
    {
        array_filter([1], [$this, 'bindHidden']);
    }

    public function builtinCallbackChoice(): void
    {
        array_map('trim', [' a ']);
        array_filter([1]);
        $this->bind('Service')->to('Enabled');
    }

    public function bindHidden(): bool
    {
        $this->bind('Service')->to('Hidden');

        return true;
    }

    public function delayedQualifier(): void
    {
        $bind = $this->bind('Service');
        $bind->annotatedWith('qualified');
        $bind->to('Enabled');
    }

    public function retainedUntargetedQualifier(): void
    {
        $bind = $this->bind(\Acme\Shop\EmptyForwarder::class);
        $bind->annotatedWith('later');
    }

    public function literalQualifier(): void
    {
        $this->bind('Service')->annotatedWith('\\Fx\\Q')->to('Enabled');
    }
}
