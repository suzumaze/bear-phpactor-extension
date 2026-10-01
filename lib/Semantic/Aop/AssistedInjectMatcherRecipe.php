<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Aop;

use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSource;

/** Deliberately narrow source fingerprints for the reviewed Ray.Di matcher sources (2.20.0 and 2.23.1). */
final class AssistedInjectMatcherRecipe
{
    public const CLASS_NAME = 'Ray\\Di\\Matcher\\AssistedInjectMatcher';

    /**
     * Normalized-token fingerprints of vendor/ray/di/src/di/Matcher/AssistedInjectMatcher.php as shipped.
     * Both read the method's parameters identically in matchesMethod(); 2.23.1 differs only in the
     * exception matchesClass() throws (InvalidAssistedInjectMatch instead of LogicException), which this
     * recipe never evaluates because class-matcher use stays unresolved.
     */
    private const VERIFIED_FINGERPRINTS = [
        'ray/di 2.20.0' => 'f637de29fe244567f9f10ddb12d19f1c8925d0e6482791c780a5c794dfb8ef7d',
        'ray/di 2.23.1' => '6c548d5067b7bc7f670d7ff9a69bf0f6a37b0cacfce1a2bfe70c1f376f20eb2e',
    ];

    public static function matches(ClassSource $source): bool
    {
        if (strcasecmp($source->name, self::CLASS_NAME) !== 0) {
            return false;
        }

        $fingerprint = self::fingerprint($source->contents);
        foreach (self::VERIFIED_FINGERPRINTS as $verified) {
            if (hash_equals($verified, $fingerprint)) {
                return true;
            }
        }

        return false;
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
