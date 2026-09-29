<?php

declare(strict_types=1);

namespace BEAR\Package\Module;

use BEAR\AppMeta\AbstractAppMeta;
use Ray\Di\AbstractModule;

final class WriteModule extends AbstractModule
{
    public function __construct(
        private AbstractAppMeta $appMeta,
        private string $context,
        ?AbstractModule $module = null,
    ) {
        parent::__construct($module);
    }

    protected function configure(): void
    {
        $this->bind(WriteDirs::class)->toInstance(new WriteDirs($this->appMeta->tmpDir, $this->appMeta->logDir));
        $this->bind(WriteShape::class)->toInstance(
            new WriteShape('var/tmp/' . $this->context, 'var/log/' . $this->context),
        );
    }
}
