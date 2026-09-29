<?php

declare(strict_types=1);

namespace Acme\Shop\Probe;

use Acme\Shop\Annotation\Injectable;
use Ray\Di\Di\Assisted;

final class AssistedProbe
{
    public function assisted(#[Assisted] object $value): void
    {
    }

    public function injected(#[Injectable] object $value): void
    {
    }

    public function negative(object $value): void
    {
    }

    public function unknown(#[MissingInjectable] object $value): void
    {
    }
}
