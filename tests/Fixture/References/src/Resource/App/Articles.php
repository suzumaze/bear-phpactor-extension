<?php

declare(strict_types=1);

namespace Acme\Refs\Resource\App;

use BEAR\Resource\ResourceObject;

#[Link(rel: 'goArticle', href: 'app://self/article{?id}')]
final class Articles extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [];

        return $this;
    }
}
