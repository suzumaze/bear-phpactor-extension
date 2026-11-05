<?php

declare(strict_types=1);

namespace BodyDocblock\Resource\Page;

use BEAR\Resource\ResourceObject;

/**
 * @property array<string, mixed> $body
 * @phpstan-property array{title: string} $body
 */
final class Stale extends ResourceObject
{
    public function onGet(): static
    {
        // The docblock was not updated when `subtitle` was added.
        $this->body = ['title' => 'Stale', 'subtitle' => 'new'];

        return $this;
    }

    public function onPost(): static
    {
        $this->body = ['title' => 'Stale'];

        return $this;
    }
}
