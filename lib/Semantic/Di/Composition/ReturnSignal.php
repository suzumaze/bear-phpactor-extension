<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di\Composition;

use RuntimeException;

/**
 * Unwinds an interpreted method on `return`.
 */
final class ReturnSignal extends RuntimeException
{
    public function __construct(public readonly mixed $value)
    {
        parent::__construct();
    }
}
