<?php

declare(strict_types=1);

namespace Acme\Blog\Resource\App;

use BEAR\Resource\ResourceObject;

final class Article extends ResourceObject
{
    #[\BEAR\Resource\Annotation\Link(rel: 'author', href: 'app://self/user')]
    public function onGet(): static
    {
        $this->body = [
            'id' => 1,
            'title' => 'hello',
        ];

        return $this;
    }
}
