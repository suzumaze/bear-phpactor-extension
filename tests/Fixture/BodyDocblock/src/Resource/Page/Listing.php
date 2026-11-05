<?php

declare(strict_types=1);

namespace BodyDocblock\Resource\Page;

use BEAR\Resource\ResourceObject;

/** @property list<string>|array{} $body */
final class Listing extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [];

        return $this;
    }
}
