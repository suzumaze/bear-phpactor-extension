<?php

declare(strict_types=1);

namespace MyVendor\BodyFixture\Resource\App;

use BEAR\Resource\ResourceObject;

final class NoSchema extends ResourceObject
{
    public function onGet(): static
    {
        $this->body['<caret>'];

        return $this;
    }
}
