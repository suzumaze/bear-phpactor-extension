<?php

declare(strict_types=1);

namespace BodyGuards\Resource\Page;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;
use RuntimeException;

/** Shapes from review: what counts as replacing the embedded body, and on which path. */
final class Variants extends ResourceObject
{
    #[Embed(rel: 'entries', src: 'app://self/entries{?page}')]
    public function onGet(int $page): static
    {
        // Another object's body, and code that may never run: not this Resource's body.
        $other = new class {
            public array $body = [];

            public function reset(): void
            {
                $this->body = ['k' => 1];
            }
        };
        $later = function (): void {
            $this->body = ['late' => 1];
        };
        $arrow = fn () => $this->body = ['arrow' => 1];
        $this->body['page'] = $page;

        return $this;
    }

    #[Embed(rel: 'entries', src: 'app://self/entries{?page}')]
    public function onPost(int $page): static
    {
        if ($page > 0) {
            $this->body['page'] = $page;
        } else {
            $this->body = ['message' => 'page must be positive'];

            return $this;
        }

        return $this;
    }

    #[Embed(rel: 'entries', src: 'app://self/entries{?page}')]
    public function onPut(int $page): static
    {
        if ($page === 0) {
            $this->body = ['message' => 'missing'];
            throw new RuntimeException('missing');
        }

        return $this;
    }

    #[Embed(rel: 'entries', src: 'app://self/entries{?page}')]
    public function onPatch(int $page): static
    {
        if ($page === 0) :
            $this->body = ['message' => 'missing'];

            return $this;
        endif;

        return $this;
    }

    #[Embed(rel: 'entries', src: 'app://self/entries{?page}')]
    public function onDelete(int $page): static
    {
        foreach ([$page] as $id) {
            $this->body = ['deleted' => $id];
        }

        return $this;
    }
}
