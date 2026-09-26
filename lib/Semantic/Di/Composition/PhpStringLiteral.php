<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di\Composition;

use Microsoft\PhpParser\Node\StringLiteral;

/** Decodes non-interpolated PHP literals without evaluating PHP code. */
final class PhpStringLiteral
{
    public static function decode(StringLiteral $node): string
    {
        $literal = $node->getText();
        $single = str_starts_with($literal, "'");
        if (str_starts_with($literal, '<<<')) {
            $single = preg_match('/^<<<[ \t]*\x27/', $literal) === 1;
            $text = $node->getStringContentsText();
            // The newline before the closing identifier is not part of the value.
            $text = preg_replace('/\r?\n$/', '', $text) ?? $text;
            $closing = $node->endQuote->getText($node->getFileContents());
            preg_match('/^[ \t]*/', $closing, $indent);
            if (($indent[0] ?? '') !== '') {
                $text = preg_replace('/^' . preg_quote($indent[0], '/') . '/m', '', $text) ?? $text;
            }
            if ($single) {
                return $text;
            }
        } else {
            // Strip exactly one closing quote; the parser's helper trims repeated quotes.
            $text = substr($literal, 1, -1);
        }
        if ($single) {
            return preg_replace_callback('/\\\\([\\\\\x27])/', static fn (array $m): string => $m[1], $text) ?? $text;
        }

        return preg_replace_callback(
            '/\\\\(?:[nrtvef\\\\$\x22]|[0-7]{1,3}|x[0-9a-fA-F]{1,2}|u\{[0-9a-fA-F]+\})/',
            static function (array $match): string {
                $escape = substr($match[0], 1);
                if (str_starts_with($escape, 'u{')) {
                    return self::utf8((int) hexdec(substr($escape, 2, -1)));
                }
                if (str_starts_with($escape, 'x')) {
                    return chr((int) hexdec(substr($escape, 1)));
                }
                if (ctype_digit($escape)) {
                    return chr((int) octdec($escape));
                }

                return match ($escape) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'v' => "\v",
                    'e' => "\e",
                    'f' => "\f",
                    default => $escape,
                };
            },
            $text,
        ) ?? $text;
    }

    private static function utf8(int $codepoint): string
    {
        return match (true) {
            $codepoint < 0x80 => chr($codepoint),
            $codepoint < 0x800 => chr(0xc0 | ($codepoint >> 6)) . chr(0x80 | ($codepoint & 0x3f)),
            $codepoint < 0x10000 => chr(0xe0 | ($codepoint >> 12))
                . chr(0x80 | (($codepoint >> 6) & 0x3f)) . chr(0x80 | ($codepoint & 0x3f)),
            default => chr(0xf0 | ($codepoint >> 18)) . chr(0x80 | (($codepoint >> 12) & 0x3f))
                . chr(0x80 | (($codepoint >> 6) & 0x3f)) . chr(0x80 | ($codepoint & 0x3f)),
        };
    }
}
