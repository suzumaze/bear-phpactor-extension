<?php

declare(strict_types=1);

namespace BEAR\Package\Context;

use BEAR\Package\Provide\Error\ErrorPageFactoryInterface;
use BEAR\Package\Provide\Error\ProdVndErrorPageFactory;
use BEAR\Package\Provide\Logger\ProdMonologProvider;
use BEAR\Package\Provide\Transfer\NullOptionsRenderer;
use BEAR\RepositoryModule\Annotation\CacheDir;
use BEAR\Package\Provide\Cache\CacheDirProvider;
use BEAR\Resource\RenderInterface;
use Psr\Log\LoggerInterface;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

/**
 * Trimmed copy of BEAR.Package's ProdModule: the cache and compile modules it installs are omitted.
 */
final class ProdModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->bind(ErrorPageFactoryInterface::class)->to(ProdVndErrorPageFactory::class);
        $this->bind(LoggerInterface::class)->toProvider(ProdMonologProvider::class)->in(Scope::SINGLETON);
        $this->disableOptionsMethod();
        $this->bind('')->annotatedWith(CacheDir::class)->toProvider(CacheDirProvider::class);
    }

    private function disableOptionsMethod(): void
    {
        $this->bind(RenderInterface::class)->annotatedWith('options')->to(NullOptionsRenderer::class);
    }
}
