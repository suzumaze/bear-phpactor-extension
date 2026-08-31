<?php

declare(strict_types=1);

namespace Acme\Blog\Resource\App\Crawl;

use BEAR\Resource\ResourceObject;

final class Tags extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [
            ['tag' => 'php'],
        ];

        return $this;
    }
}
