<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Router;

/**
 * Aura.Router のルートパスを Page リソースのファイル名に変換する。
 *
 * idea-php-bearsunday-plugin の RouterUtil.toResourceFileName() と同じ規則:
 * '/' と '-' で区切られた各語の先頭文字を大文字にし、残りを小文字にする。'-' は除去する。
 *
 *   '/index'          -> 'Index.php'
 *   '/user/profile'   -> 'User/Profile.php'
 *   '/user-profile'   -> 'UserProfile.php'
 *   '/api/user-profile' -> 'Api/UserProfile.php'
 */
final class RouterUtil
{
    private function __construct()
    {
    }

    public static function toResourceFileName(string $path): string
    {
        $segments = explode('/', $path);
        $converted = array_map(
            static fn (string $segment): string => self::capitalizeSegment($segment),
            $segments
        );

        return implode('/', $converted) . '.php';
    }

    private static function capitalizeSegment(string $segment): string
    {
        $words = explode('-', $segment);
        $words = array_map(
            static fn (string $word): string => ucfirst(strtolower($word)),
            $words
        );

        return implode('', $words);
    }
}
