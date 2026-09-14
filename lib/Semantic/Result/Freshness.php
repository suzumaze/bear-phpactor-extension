<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Result;

/**
 * Whether semantic evidence came from saved storage or an editor buffer.
 */
enum Freshness: string
{
    case Saved = 'saved';
    case Buffer = 'buffer';
}
