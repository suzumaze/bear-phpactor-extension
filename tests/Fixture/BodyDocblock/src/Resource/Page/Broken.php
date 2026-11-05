<?php

declare(strict_types=1);

namespace BodyDocblock\Resource\Page;

use BEAR\Resource\ResourceObject;

/** @property array{title: string $body */
final class Broken extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = ['title' => 'Broken'];

        return $this;
    }
}
