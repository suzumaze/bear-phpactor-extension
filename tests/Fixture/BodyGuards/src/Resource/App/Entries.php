<?php

declare(strict_types=1);

namespace BodyGuards\Resource\App;

use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;

final class Entries extends ResourceObject
{
    public function onGet(int $page): static
    {
        $tag = $this->resource->get('app://self/tag', ['page' => $page]);
        if ($tag->code !== Code::OK) {
            return $this;
        }

        $this->body = [
            'tag' => $tag->body,
            'articles' => $this->getArticles($page),
        ];

        return $this;
    }

    public function onPost(string $title): static
    {
        if ($title === '') {
            $this->body = ['message' => 'title is required'];

            return $this;
        }
        $this->body = ['id' => 1];
        if ($this->code === Code::CREATED) {
            return $this;
        }
        $this->body['title'] = $title;

        return $this;
    }

    public function onPut(int $id): static
    {
        if ($id === 0) {
            $this->body = ['message' => 'missing'];
        }
        $this->body = ['id' => $id];

        return $this;
    }

    public function onPatch(): static
    {
        $this->body = ['a' => 1];
        // The condition runs on the main path and removes the key it just checked.
        if (array_pop($this->body) === null) {
            return $this;
        }

        return $this;
    }

    /** @return list<array{id: int}> */
    private function getArticles(int $page): array
    {
        return [['id' => $page]];
    }
}
