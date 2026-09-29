<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Aop;

use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSource;

/** A deliberately narrow source fingerprint for Ray.Di 2.20.0's reviewed matcher source. */
final class AssistedInjectMatcherRecipe
{
    public const CLASS_NAME = 'Ray\\Di\\Matcher\\AssistedInjectMatcher';

    // Source: ray/di 2.20.0, vendor/ray/di/src/di/Matcher/AssistedInjectMatcher.php.
    private const SOURCE_FINGERPRINT = 'f637de29fe244567f9f10ddb12d19f1c8925d0e6482791c780a5c794dfb8ef7d';

    public static function matches(ClassSource $source): bool
    {
        return strcasecmp($source->name, self::CLASS_NAME) === 0
            && hash_equals(self::SOURCE_FINGERPRINT, self::fingerprint($source->contents));
    }

    /** Ignore comments and whitespace while retaining every executable and declaration token. */
    public static function fingerprint(string $contents): string
    {
        $tokens = [];
        foreach (token_get_all($contents) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $text = $token[0] === T_OPEN_TAG ? rtrim($token[1]) : $token[1];
                $tokens[] = token_name($token[0]) . ':' . $text;
                continue;
            }
            $tokens[] = $token;
        }

        return hash('sha256', implode("\0", $tokens));
    }
}
