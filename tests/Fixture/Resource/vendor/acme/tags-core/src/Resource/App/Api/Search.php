<?php

declare(strict_types=1);

namespace Acme\Tags\Resource\App\Api;

use BEAR\Resource\ResourceObject;

final class Search extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [];

        return $this;
    }
}
