<?php

declare(strict_types=1);

namespace ContractFixture\Resource\App;

use BEAR\ApiDoc\Annotation\Alps;
use BEAR\Resource\Annotation\JsonSchema;
use BEAR\Resource\ResourceObject;

final class User extends ResourceObject
{
    #[Alps('getUser')]
    public function onGet(): static
    {
        $this->body = [
            'id' => 1,
            'name' => 'Alice',
        ];

        return $this;
    }

    #[Alps('createUser')]
    #[JsonSchema(params: 'user-params.json')]
    public function onPost(int $id, string $name): static
    {
        return $this;
    }

    #[Alps(SOME_DESCRIPTOR)]
    #[JsonSchema(params: SOME_SCHEMA)]
    public function onPatch(int $id): static
    {
        return $this;
    }

    #[Alps('missingDescriptor')]
    #[JsonSchema(params: 'missing-params.json', schema: 'missing-response.json')]
    public function onDelete(int $id): static
    {
        return $this;
    }
}
