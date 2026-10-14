<?php

namespace Vendor\App\Resource\App;

class Example extends \BEAR\Resource\ResourceObject
{
    #[\BEAR\Resource\Annotation\JsonSchema(schema: 'output.json', params: 'input.json')]
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }

    #[\BEAR\Resource\Annotation\JsonSchema(schema: 'output.json', params: 'input.json')]
    public function onPost(int $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }

    #[\BEAR\Resource\Annotation\JsonSchema(schema: 'output.json', params: 'input.json')]
    public function onPut(int $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }
}
