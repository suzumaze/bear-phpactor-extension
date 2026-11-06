<?php

declare(strict_types=1);

namespace AbsentMembers\Resource\App;

use BEAR\Resource\Annotation\Link;
use BEAR\Resource\ResourceObject;

final class Entry extends ResourceObject
{
    private const EDIT_METHOD = 'put';

    // Each member the handler leaves null here is absent from the stdio response.
    #[Link(rel: 'edit', href: 'app://self/entry{?id}', method: self::EDIT_METHOD, title: null)]
    public function onGet(int $id): static
    {
        if ($id === 0) {
            return $this;
        }
        $this->body = ['id' => $id];

        return $this;
    }
}
