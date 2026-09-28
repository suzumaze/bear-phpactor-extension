<?php

declare(strict_types=1);

namespace Acme\Shop;

final class EmptyForwarder
{
    public static function getInstance(string $context): void
    {
    }
}
