<?php

declare(strict_types=1);

namespace Acme\Shop\Annotation;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Injectable implements \Ray\Di\Di\InjectInterface
{
    public function isOptional(): bool
    {
        return false;
    }
}
