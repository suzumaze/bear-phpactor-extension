<?php

namespace Vendor\App\Resource\Page\Content;

use BEAR\Resource\Annotation\JsonSchema;
use BEAR\Resource\ResourceObject;

class SpecialPage extends ResourceObject
{
    #[JsonSchema(schema: 'output.json', params: 'input.json')]
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }

    #[JsonSchema(schema: '0')]
    public function onPost(): void
    {
    }
}
