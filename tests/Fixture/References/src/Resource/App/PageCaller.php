<?php

declare(strict_types=1);

namespace Acme\Refs\Resource\App;

use BEAR\Resource\ResourceObject;

final class PageCaller extends ResourceObject
{
    public function onGet(): static
    {
        $this->resource->get('page://self/article');

        return $this;
    }
}
