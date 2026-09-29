<?php

declare(strict_types=1);

namespace Ray\Di;

abstract class AbstractModule
{
    /** @var Matcher */
    protected $matcher;

    /** @var AbstractModule|null */
    protected $lastModule;

    private ?Container $container = null;

    public function __construct(?self $module = null)
    {
        $this->lastModule = $module;
        $this->container = new Container();
        $this->configure();
        if ($module instanceof self) {
            $this->getContainer()->merge($module->getContainer());
        }
    }

    public function install(self $module): void
    {
        $this->getContainer()->merge($module->getContainer());
    }

    public function override(self $module): void
    {
        $module->getContainer()->merge($this->getContainer());
        $this->container = $module->getContainer();
    }

    public function getContainer(): Container
    {
        return $this->container ?? new Container();
    }

    abstract protected function configure();

    protected function bind(string $interface = ''): Bind
    {
        return new Bind($this->getContainer(), $interface, static::class);
    }
}
