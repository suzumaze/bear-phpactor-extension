<?php

declare(strict_types=1);

namespace BEAR\Package\Module;

use BEAR\Package\Provide\Error\NullPage;
use Generator;
use Ray\Di\AbstractModule;

final class ResourceObjectModule extends AbstractModule
{
    public function __construct(
        private Generator $resourceObjects,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->install(new \BEAR\Resource\Module\ResourceObjectModule($this->getResourceObjects()));
        $this->bind(NullPage::class);
    }

    private function getResourceObjects(): Generator
    {
        foreach ($this->resourceObjects as [$class]) {
            yield $class;
        }
    }
}
