<?php

declare(strict_types=1);

namespace Acme\Refs\Resource\Page\Admin;

use BEAR\Resource\ResourceObject;

final class Article extends ResourceObject
{
    public function onPost(): static
    {
        $this->resource->post('app://self/article', ['title' => 'x']);

        return $this;
    }
}
