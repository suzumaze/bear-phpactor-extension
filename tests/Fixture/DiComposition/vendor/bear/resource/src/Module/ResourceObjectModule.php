<?php

declare(strict_types=1);

namespace BEAR\Resource\Module;

use Ray\Di\AbstractModule;

final class ResourceObjectModule extends AbstractModule
{
    public function __construct(
        private readonly iterable $resourceObjects,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        foreach ($this->resourceObjects as $ro) {
            $this->bind($ro);
        }
    }
}
