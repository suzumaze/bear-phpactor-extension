<?php

declare(strict_types=1);

namespace MyVendor\BodyFixture\Resource\App;

use BEAR\Resource\Annotation\JsonSchema;
use BEAR\Resource\ResourceObject;

final class Broken extends ResourceObject
{
    #[JsonSchema('broken.json')]
    public function onGet(): static
    {
        $this->body['<caret>'];

        return $this;
    }
}
