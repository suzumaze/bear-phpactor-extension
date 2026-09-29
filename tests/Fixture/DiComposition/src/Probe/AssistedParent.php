<?php

declare(strict_types=1);

namespace Acme\Shop\Probe;

use Ray\Di\Di\Assisted;

class AssistedParent
{
    public function inherited(#[Assisted] object $value): void
    {
    }

    public function overridden(#[Assisted] object $value): void
    {
    }
}
