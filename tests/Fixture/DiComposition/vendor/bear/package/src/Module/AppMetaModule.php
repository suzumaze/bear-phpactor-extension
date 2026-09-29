<?php

declare(strict_types=1);

namespace BEAR\Package\Module;

use BEAR\AppMeta\AbstractAppMeta;
use BEAR\Package\Annotation\AsCompiled;
use BEAR\Package\Annotation\AppName;
use BEAR\Package\Compile\CompileStepInterface;
use BEAR\Sunday\Extension\Application\AppInterface;
use Ray\Di\AbstractModule;
use Ray\Di\MultiBinder;
use Ray\Di\Scope;

class AppMetaModule extends AbstractModule
{
    public function __construct(private AbstractAppMeta $appMeta, ?AbstractModule $module = null)
    {
        parent::__construct($module);
    }

    protected function configure(): void
    {
        $this->bind(AbstractAppMeta::class)->annotatedWith(AsCompiled::class)->toInstance($this->appMeta);
        $this->bind(AbstractAppMeta::class)->toProvider(AppMetaProvider::class)->in(Scope::SINGLETON);
        $appClass = $this->appMeta->name . '\Module\App';
        assert(class_exists($appClass));
        $this->bind(AppInterface::class)->to($appClass)->in(Scope::SINGLETON);
        $this->bind()->annotatedWith(AppName::class)->toInstance($this->appMeta->name);
        MultiBinder::newInstance($this, CompileStepInterface::class);
    }
}
