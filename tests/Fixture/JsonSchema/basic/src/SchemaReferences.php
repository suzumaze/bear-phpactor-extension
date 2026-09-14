<?php

declare(strict_types=1);

namespace MyVendor\JsonSchemaFixture;

use BEAR\Resource\Annotation\JsonSchema as Schema;

interface SchemaReferences
{
    #[Schema('user.json')]
    public function first(): array;

    #[Schema(schema: 'user.json')]
    public function second(): array;

    #[Schema(params: 'user-params.json')]
    public function request(): array;

    #[\Other\JsonSchema('user.json')]
    public function foreign(): array;

    #[Schema(type: 'user.json')]
    public function unsupportedArgument(): array;
}
