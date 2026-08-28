<?php

declare(strict_types=1);

namespace MyVendor\JsonSchemaFixture\Resource\App;

use BEAR\Resource\ResourceObject;

final class User<caret> extends ResourceObject
{
    public function onGet(): array
    {
        return [];
    }
}
