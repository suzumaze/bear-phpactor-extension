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

    public static function pickAfterSwitch(): string
    {
        switch (getenv('FEATURE')) {
            case '1':
                return 'Enabled';
        }

        return 'Fallback';
    }

    public static function pickAfterWhile(): string
    {
        while (getenv('FEATURE')) {
            return 'Enabled';
        }

        return 'Fallback';
    }

    public static function pickAfterForeach(): string
    {
        foreach (explode(',', (string) getenv('FEATURES')) as $feature) {
            if ($feature === 'enabled') {
                return 'Enabled';
            }
        }

        return 'Fallback';
    }

    public static function pickAfterTry(): string
    {
        try {
            if (getenv('FEATURE')) {
                throw new \RuntimeException();
            }
        } catch (\RuntimeException) {
            return 'Enabled';
        }

        return 'Fallback';
    }
}
