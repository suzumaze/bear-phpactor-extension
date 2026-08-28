<?php

declare(strict_types=1);

namespace MyVendor\JsonSchemaFixture\Resource\App\Cache;

use BEAR\Resource\ResourceObject;

final class AuthorList<caret> extends ResourceObject
{
    public function onGet(): array
    {
        return [];
    }
}
