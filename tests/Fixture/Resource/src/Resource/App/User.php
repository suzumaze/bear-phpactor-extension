<?php

declare(strict_types=1);

namespace Acme\Blog\Resource\App;

use BEAR\Resource\ResourceObject;

final class User extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [
            'id' => 1,
            'name' => 'BEAR',
        ];

        return $this;
    }
}
