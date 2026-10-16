<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Template;

/** Bounded caller supplies the text. Extracts source references; never evaluates Twig. */
final class TwigDependencyScanner
{
    /** @return array{references: list<array<string, mixed>>, complete: bool} */
    public function scan(string $text): array
    {
        $references = [];
        $contexts = [];
        $verbatim = false;
        $complete = true;
        $cursor = 0;
        while (preg_match('/\{[%{#]/', $text, $start, PREG_OFFSET_CAPTURE, $cursor) === 1) {
            $offset = $start[0][1];
            $open = $start[0][0];
            $close = match ($open) {
                '{%' => '%}', '{{' => '}}', default => '#}'
            };
            $end = $open === '{#' ? strpos($text, $close, $offset + 2)
                : $this->tagEnd($text, $offset + 2, $close);
            if ($end === false) {
                $complete = false;
                break;
            }
            $cursor = $end + 2;
            if ($open === '{#') {
                continue;
            }
            $code = substr($text, $offset + 2, $end - $offset - 2);
            $trimmed = trim($code, " \r\n\t-~");
            $kind = preg_match('/^(\w+)\b/', $trimmed, $word) === 1 ? $word[1] : '';
            if ($verbatim) {
                if ($open === '{%' && $kind === 'endverbatim') {
                    $verbatim = false;
                }
                continue;
            }
            if ($open === '{%' && $kind === 'verbatim') {
                $verbatim = true;
                continue;
            }
            $line = substr_count(substr($text, 0, $offset), "\n") + 1;
            $shortBlock = $open === '{%' && $kind === 'block'
                && preg_match('/^block\s+[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(.*)$/s', $trimmed, $block) === 1
                && trim($block[1]) !== '';
            $referenceContexts = $shortBlock ? [...$contexts, 'block'] : $contexts;
            if ($open === '{%' && str_starts_with($kind, 'end')) {
                $ending = substr($kind, 3);
                if (in_array($ending, ['if', 'for', 'block', 'macro', 'embed'], true)) {
                    if (array_pop($contexts) !== $ending) {
                        $complete = false;
                    }
                }
            }
            if ($open === '{%' && in_array($kind, ['include', 'extends', 'embed', 'use', 'import', 'from'], true)) {
                $expression = ltrim(substr($trimmed, strlen($kind)));
                $references[] = $this->reference($kind, $expression, $contexts, $line, false);
            }
            // Skip quoted strings so plain text such as "include('example')" is not a call.
            for ($i = 0, $length = strlen($code); $i < $length; ++$i) {
                if ($code[$i] === "'" || $code[$i] === '"') {
                    $i = $this->quoteEnd($code, $i) ?? ($length - 1);
                    continue;
                }
                if (
                    ($i === 0 || preg_match('/[\w.]/', $code[$i - 1]) !== 1)
                    && preg_match('/\G(include|resource|source)\s*\(/A', $code, $call, 0, $i) === 1
                ) {
                    $expression = substr($code, $i + strlen($call[0]));
                    $ref = $this->reference($call[1], $expression, $referenceContexts, $line, true);
                    // A call inside a Twig expression may be guarded by a ternary or short circuit.
                    $ref['conditional'] = $ref['conditional'] || str_contains($code, '?')
                        || preg_match('/\b(?:and|or)\b/', $code) === 1 || in_array($kind, ['if', 'elseif'], true);
                    $references[] = $ref;
                    $i += strlen($call[0]) - 1;
                }
            }
            if ($open === '{%' && !$shortBlock && in_array($kind, ['if', 'for', 'block', 'macro', 'embed'], true)) {
                $contexts[] = $kind;
            }
        }

        return ['references' => $references, 'complete' => $complete && $contexts === [] && !$verbatim];
    }

    /**
     * @param list<string> $contexts
     * @return array<string, mixed>
     */
    private function reference(string $kind, string $expression, array $contexts, int $line, bool $function): array
    {
        $expression = ltrim($expression);
        $end = $expression !== '' && in_array($expression[0], ["'", '"'], true)
            ? $this->quoteEnd($expression, 0) : null;
        $name = null;
        if ($end !== null) {
            $tail = ltrim(substr($expression, $end + 1));
            $allowed = $function ? preg_match('/^[,)]/', $tail) === 1 : match ($kind) {
                'include', 'embed' => $tail === '' || preg_match('/^(?:with|only|ignore\s+missing)\b/', $tail) === 1,
                'import' => preg_match('/^as\s+\w+$/', $tail) === 1,
                'from' => preg_match('/^import\s+/', $tail) === 1,
                'use' => $tail === '' || preg_match('/^with\s+/', $tail) === 1,
                default => $tail === '',
            };
            $literal = substr($expression, 1, $end - 1);
            // Interpolation and escape semantics are deliberately not guessed.
            if ($allowed && !str_contains($literal, '\\') && !str_contains($literal, '#{')) {
                $name = $literal;
            }
        }

        return [
            'kind' => $kind, 'name' => $name, 'line' => $line,
            'status' => $name === null ? 'dynamic' : 'static',
            'conditional' => array_intersect($contexts, ['if', 'for']) !== [],
            'contexts' => $contexts, 'conditionScope' => 'source_local',
            'ignoreMissing' => !$function && preg_match('/\bignore\s+missing\b/', $expression) === 1,
        ];
    }

    private function tagEnd(string $text, int $offset, string $close): int|false
    {
        for ($length = strlen($text); $offset < $length - 1; ++$offset) {
            if (substr($text, $offset, 2) === $close) {
                return $offset;
            }
            if ($text[$offset] === "'" || $text[$offset] === '"') {
                $quote = $this->quoteEnd($text, $offset);
                if ($quote === null) {
                    return false;
                }
                $offset = $quote;
            }
        }

        return false;
    }

    private function quoteEnd(string $text, int $start): ?int
    {
        $quote = $text[$start];
        for ($i = $start + 1, $length = strlen($text); $i < $length; ++$i) {
            if ($text[$i] === '\\') {
                ++$i;
            } elseif ($text[$i] === $quote) {
                return $i;
            }
        }

        return null;
    }
}
