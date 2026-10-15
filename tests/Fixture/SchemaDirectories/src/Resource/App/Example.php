<?php

namespace Vendor\App\Resource\App;

class Example extends \BEAR\Resource\ResourceObject
{
    #[\BEAR\Resource\Annotation\JsonSchema(schema: 'record.json', params: 'entry.json')]
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }
}
