<?php

declare(strict_types=1);

namespace MyVendor\BodyFixture\Resource\App;

use BEAR\Resource\ResourceObject;

final class User extends ResourceObject
{
    public function onGet(): static
    {
        $this->body['<caret-1>'];
        $this->body['na<caret-2>'];
        $other->body['<caret-3>'];

        return $this;
    }
}
