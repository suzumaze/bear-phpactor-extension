<?php

namespace BEAR\QiqModule;

use Ray\Di\AbstractModule;

/** The constructor and bindings of BEAR.QiqModule that decide where templates are found. */
final class QiqModule extends AbstractModule
{
    public function __construct(
        private string $templateDir,
        private AbstractModule|null $module = null,
    ) {
        parent::__construct($this->module);
    }

    protected function configure(): void
    {
        $this->bind()->annotatedWith('qiq_template_dir')->toInstance($this->templateDir);
        $this->bind()->annotatedWith('qiq_paths')->toInstance([$this->templateDir]);
    }
}
