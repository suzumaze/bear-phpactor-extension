<?php

declare(strict_types=1);

namespace Acme\Refs\Resource\Page;

use BEAR\Resource\ResourceObject;

#[Embed(rel: '_self', src: 'app://self/article')]
final class Article extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [];

        return $this;
    }
}
