<?php

declare(strict_types=1);

namespace MyVendor\JsonSchemaFixture\Resource\Page\Admin;

use BEAR\Resource\ResourceObject;

final class UserProfile<caret> extends ResourceObject
{
    public function onGet(): array
    {
        return [];
    }
}
