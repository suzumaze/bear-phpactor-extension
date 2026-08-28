<?php

declare(strict_types=1);

namespace MyVendor\JsonSchemaFixture\Resource\App;

use BEAR\Resource\Annotation\JsonSchema;
use BEAR\Resource\ResourceObject;

final class SchemaDemo extends ResourceObject
{
    #[JsonSchema('user<caret-1>.json')]
    public function onGet(): array
    {
        return [];
    }

    #[JsonSchema(schema: 'user<caret-2>.json')]
    public function onPost(): array
    {
        return [];
    }

    #[JsonSchema(params: 'user-<caret-3>params.json')]
    public function onPut(): array
    {
        return [];
    }

    #[JsonSchema('missing<caret-4>.json')]
    public function onDelete(): array
    {
        return [];
    }

    #[JsonSchema('../<caret-5>escape.json')]
    public function onPatch(): array
    {
        return [];
    }
}
