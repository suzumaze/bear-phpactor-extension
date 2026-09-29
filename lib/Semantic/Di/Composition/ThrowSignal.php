<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di\Composition;

use RuntimeException;

/**
 * Unwinds an interpreted method that reached a `throw`.
 */
final class ThrowSignal extends RuntimeException
{
}
