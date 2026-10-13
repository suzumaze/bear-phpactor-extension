<?php

namespace Vendor\App\Resource\App;

use BEAR\Resource\Annotation\JsonSchema;
use BEAR\Resource\ResourceObject;

class Example extends ResourceObject
{
    #[JsonSchema(schema: 'explicit.json', params: 'input.json')]
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }

    #[JsonSchema('second.json', '', 'second-input.json')]
    public function onPost(int $id): void
    {
    }

    #[JsonSchema(schema: UNKNOWN_SCHEMA)]
    public function onPatch(): void
    {
    }

    #[JsonSchema(schema: '')]
    public function onPut(): void
    {
    }

    public function onDelete(): void
    {
    }
}
