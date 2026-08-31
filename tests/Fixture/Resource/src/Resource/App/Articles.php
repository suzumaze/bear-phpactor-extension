<?php

declare(strict_types=1);

namespace Acme\Blog\Resource\App;

use BEAR\Resource\ResourceObject;

#[Embed(rel: 'authorList', src: 'app://self/user')]
#[Link(rel: 'goCategoryList', href: 'app://self/blog/posts')]
#[Embed(rel: 'article', src: 'app://self/article{?id}')]
#[Link(rel: 'goTags', href: 'app://self/crawl/tags?articleId={id}')]
final class Articles extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [
            ['id' => 1, 'title' => 'hello'],
        ];

        return $this;
    }
}
