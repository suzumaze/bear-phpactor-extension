<?php

declare(strict_types=1);

namespace BodyDocblock\Resource\Page;

use BEAR\Resource\ResourceObject;

/** @property-read array{'page-title': string, ...} $body */
final class Open extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = ['page-title' => 'Open', 'extra' => true];

        return $this;
    }
}
