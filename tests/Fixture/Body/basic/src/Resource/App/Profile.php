<?php

declare(strict_types=1);

namespace MyVendor\BodyFixture\Resource\App;

use BEAR\Resource\Annotation\JsonSchema;
use BEAR\Resource\ResourceObject;

final class Profile extends ResourceObject
{
    #[JsonSchema('profile.json')]
    public function onGet(): static
    {
        $this->body['<caret-1>'];

        return $this;
    }

    #[JsonSchema(params: 'profile-params.json')]
    public function onPost(): static
    {
        $this->body['<caret-2>'];

        return $this;
    }

    #[JsonSchema(schema: 'profile.json')]
    public function onPut(): static
    {
        $this->body['<caret-3>'];

        return $this;
    }
}
