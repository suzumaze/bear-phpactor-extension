<?php

declare(strict_types=1);

namespace Acme\Refs\Resource\App;

use BEAR\Resource\ResourceObject;

#[Link(rel: 'goIndirect', href: 'app://self/indirectArticle')]
final class IndirectCaller extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [];

        return $this;
    }
}
