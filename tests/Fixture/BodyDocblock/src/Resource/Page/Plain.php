<?php

declare(strict_types=1);

namespace BodyDocblock\Resource\Page;

use BEAR\Resource\ResourceObject;

/** A docblock without a body declaration. */
final class Plain extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = ['title' => 'Plain'];

        return $this;
    }
}
