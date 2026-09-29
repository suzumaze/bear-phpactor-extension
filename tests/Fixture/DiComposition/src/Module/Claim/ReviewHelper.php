<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

final class ReviewHelper
{
    public static function pick(): string
    {
        if (getenv('FEATURE')) {
            return 'Enabled';
        }

        return 'Fallback';
    }
}
