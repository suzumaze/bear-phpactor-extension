<?php

declare(strict_types=1);

namespace Acme\Blog\Resource\Page;

use BEAR\Resource\ResourceObject;

final class Y extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [];

        return $this;
    }
}
