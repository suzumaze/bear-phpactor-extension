<?php

declare(strict_types=1);

namespace MyVendor\BodyFixture\Resource\App;

use BEAR\Resource\ResourceObject;

final class OtherBody extends ResourceObject
{
    public function onGet(): static
    {
        $other->body['<caret>'];

        return $this;
    }
}
