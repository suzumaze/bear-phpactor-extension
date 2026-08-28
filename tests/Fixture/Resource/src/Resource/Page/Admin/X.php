<?php

declare(strict_types=1);

namespace Acme\Blog\Resource\Page\Admin;

use BEAR\Resource\ResourceObject;

final class X extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [];

        return $this;
    }
}
