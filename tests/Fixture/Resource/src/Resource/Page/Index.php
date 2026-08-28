<?php

declare(strict_types=1);

namespace Acme\Blog\Resource\Page;

use BEAR\Resource\ResourceObject;

final class Index extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [
            'greeting' => 'Hello BEAR',
        ];

        return $this;
    }
}
