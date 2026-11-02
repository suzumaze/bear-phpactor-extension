<?php

declare(strict_types=1);

namespace BodyGuards\Resource\Page;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\Code;
use BEAR\Resource\ResourceObject;

final class Article extends ResourceObject
{
    // The error branch replaces the embedded body on purpose; the end of the method keeps it.
    #[Embed(rel: '_self', src: 'app://self/entries{?page}')]
    public function onGet(int $page): static
    {
        if ($this->code === Code::NOT_FOUND) {
            $this->body = ['message' => 'Article not found'];

            return $this;
        }
        $this->body['label'] = 'article';

        return $this;
    }
}
