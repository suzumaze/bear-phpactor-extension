<?php

declare(strict_types=1);

namespace Acme\Shop\Resource\App;

use Acme\Shop\Annotation\ChildFirst;
use Acme\Shop\Annotation\Second;
use BEAR\Resource\ResourceObject;

final class Inheritance extends ResourceObject
{
    #[ChildFirst, Second]
    public function onGet(): void
    {
    }

    public function __probe(): void
    {
    }

    public function append(): void
    {
    }
}
