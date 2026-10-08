<?php

declare(strict_types=1);

namespace Acme\Skeleton;

final class PlainInvokable
{
    public function __invoke(string $context): string
    {
        return $context;
    }
}
