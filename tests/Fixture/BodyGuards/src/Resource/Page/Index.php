<?php

declare(strict_types=1);

namespace BodyGuards\Resource\Page;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

final class Index extends ResourceObject
{
    #[Embed(rel: 'entries', src: 'app://self/entries{?page}')]
    public function onGet(int $page): static
    {
        // Drops the embedded `entries` key on every request.
        $this->body = ['title' => 'Index'];

        return $this;
    }

    #[Embed(rel: 'entries', src: 'app://self/entries{?page}')]
    public function onPost(int $page): static
    {
        // Keeps the embedded key: the right-hand side reads the body first.
        $this->body = [...$this->body, 'title' => 'Index'];

        return $this;
    }
}
