<?php

declare(strict_types=1);

namespace BodyDocblock\Resource\Page;

use BEAR\Resource\ResourceObject;

/** @property ?array{count: ?int} $body */
final class Nullable extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = ['count' => null];

        return $this;
    }

    public function onDelete(): static
    {
        $this->body = [];

        return $this;
    }
}
