<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Template;

use Phpactor\TextDocument\TextDocument;

use function count;
use function is_array;
use function preg_match;
use function preg_match_all;
use function str_contains;
use function str_ends_with;
use function strlen;
use function strtolower;
use function substr;
use function token_get_all;

/**
 * Twig/Qiqソースから静的なテンプレート名だけを拾う軽量スキャナ。
 *
 * テンプレート言語全体の構文解析は行わない。リンク先になり得る、文字列リテラルを
 * 第1引数（Twig block()だけ第2引数）に取る既知の構文だけを対象にする。
 */
final class TemplateReferenceScanner
{
    /** @return list<TemplateReference> */
    public function references(TextDocument $document): array
    {
        return $this->referencesForEngine($document, $this->definitionEngine($document));
    }

    /**
     * Hover interception must be narrower than the historical Definition
     * heuristic: an arbitrary PHP file containing a Qiq-looking tag must not
     * suppress Phpactor's normal Hover.
     *
     * @return list<TemplateReference>
     */
    public function hoverReferences(TextDocument $document): array
    {
        return $this->referencesForEngine($document, $this->hoverEngine($document));
    }

    private function definitionEngine(TextDocument $document): ?string
    {
        $text = $document->__toString();
        $path = $document->uri()?->path() ?? '';

        if (str_ends_with(strtolower($path), '.html.twig') || $document->language()->is('twig')) {
            return TemplateReference::ENGINE_TWIG;
        }

        if (
            (
                str_ends_with(strtolower($path), '.php')
                && (str_contains($text, '{{') || str_contains($path, '/var/qiq/template/'))
            )
            || $document->language()->is('qiq')
        ) {
            return TemplateReference::ENGINE_QIQ;
        }

        return null;
    }

    private function hoverEngine(TextDocument $document): ?string
    {
        $path = $document->uri()?->path() ?? '';
        $lowerPath = strtolower($path);

        if (str_ends_with($lowerPath, '.html.twig') || $document->language()->is('twig')) {
            return TemplateReference::ENGINE_TWIG;
        }

        if (
            $document->language()->is('qiq')
            || (str_ends_with($lowerPath, '.php') && str_contains($lowerPath, '/var/qiq/template/'))
        ) {
            return TemplateReference::ENGINE_QIQ;
        }

        return null;
    }

    /** @return list<TemplateReference> */
    private function referencesForEngine(TextDocument $document, ?string $engine): array
    {
        $text = $document->__toString();

        return match ($engine) {
            TemplateReference::ENGINE_TWIG => $this->twigReferences($text),
            TemplateReference::ENGINE_QIQ => $this->qiqReferences($text),
            default => [],
        };
    }

    /** @return list<TemplateReference> */
    private function twigReferences(string $text): array
    {
        $references = [];
        $verbatim = false;

        preg_match_all('/\{\#.*?\#\}|\{%.*?%\}|\{\{.*?}}/s', $text, $tags, PREG_OFFSET_CAPTURE);
        foreach ($tags[0] as [$tag, $tagOffset]) {
            if (str_starts_with($tag, '{#')) {
                continue;
            }

            $inner = substr($tag, 2, -2);
            $innerOffset = $tagOffset + 2;
            if (preg_match('/^\s*[-~]?\s*verbatim\b/', $inner) === 1) {
                $verbatim = true;
                continue;
            }
            if (preg_match('/^\s*[-~]?\s*endverbatim\b/', $inner) === 1) {
                $verbatim = false;
                continue;
            }
            if ($verbatim) {
                continue;
            }

            if (str_starts_with($tag, '{%')) {
                $this->collectTwigTagReference($inner, $innerOffset, $references);
            }
            $this->collectTwigFunctionReferences($inner, $innerOffset, $references);
        }

        return $references;
    }

    /** @param list<TemplateReference> $references */
    private function collectTwigTagReference(string $code, int $baseOffset, array &$references): void
    {
        $literal = $this->literalPattern('literal');
        if (
            preg_match(
                '/^\s*[-~]?\s*(?:extends|include)\s+' . $literal . '/s',
                $code,
                $match,
                PREG_OFFSET_CAPTURE,
            ) !== 1
        ) {
            return;
        }

        $reference = $this->referenceFromMatch(TemplateReference::ENGINE_TWIG, $match, 'literal', $baseOffset);
        if ($reference !== null) {
            $references[] = $reference;
        }
    }

    /** @param list<TemplateReference> $references */
    private function collectTwigFunctionReferences(string $code, int $baseOffset, array &$references): void
    {
        $literal = $this->literalPattern('literal');
        preg_match_all(
            '/(?<![A-Za-z0-9_>])include\s*\(\s*' . $literal . '/s',
            $code,
            $includeMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        foreach ($includeMatches as $match) {
            $reference = $this->referenceFromMatch(
                TemplateReference::ENGINE_TWIG,
                $match,
                'literal',
                $baseOffset,
            );
            if ($reference !== null) {
                $references[] = $reference;
            }
        }

        $first = $this->literalPattern('block_name');
        $second = $this->literalPattern('template');
        preg_match_all(
            '/(?<![A-Za-z0-9_>])block\s*\(\s*' . $first . '\s*,\s*' . $second . '/s',
            $code,
            $blockMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        foreach ($blockMatches as $match) {
            $reference = $this->referenceFromMatch(
                TemplateReference::ENGINE_TWIG,
                $match,
                'template',
                $baseOffset,
            );
            if ($reference !== null) {
                $references[] = $reference;
            }
        }
    }

    /** @return list<TemplateReference> */
    private function qiqReferences(string $text): array
    {
        $references = [];

        // Qiq本体のコンパイラと同じく、最短の {{ ... }} を1タグとして扱う。
        preg_match_all('/{{.*?}}/s', $text, $qiqTags, PREG_OFFSET_CAPTURE);
        foreach ($qiqTags[0] as [$tag, $tagOffset]) {
            $code = substr($tag, 2, -2);
            $this->collectQiqCalls($code, $tagOffset + 2, false, $references);
        }

        // QiqはネイティブPHPとの混在も正式に許す。PHPタグ内の
        // $this->render()/setLayout()/extends() も同じ参照として扱う。
        preg_match_all('/<\?(?:php|=).*?(?:\?>|\z)/s', $text, $phpTags, PREG_OFFSET_CAPTURE);
        foreach ($phpTags[0] as [$tag, $tagOffset]) {
            $this->collectQiqCalls($tag, $tagOffset, true, $references);
        }

        return $references;
    }

    /** @param list<TemplateReference> $references */
    private function collectQiqCalls(
        string $code,
        int $baseOffset,
        bool $hasOpenTag,
        array &$references,
    ): void {
        $prefix = $hasOpenTag ? '' : '<?php ';
        $tokens = token_get_all($prefix . $code);
        $items = [];
        $offset = 0;
        foreach ($tokens as $token) {
            $tokenText = is_array($token) ? $token[1] : $token;
            $items[] = [
                'id' => is_array($token) ? $token[0] : null,
                'text' => $tokenText,
                'start' => $offset,
            ];
            $offset += strlen($tokenText);
        }

        foreach ($items as $index => $item) {
            $callName = $this->qiqCallName($item);
            if ($callName === null || !$this->isQiqTemplateCall($items, $index)) {
                continue;
            }

            $openParen = $this->nextSignificant($items, $index);
            if ($openParen === null || $items[$openParen]['text'] !== '(') {
                continue;
            }
            $argument = $this->nextSignificant($items, $openParen);
            if ($argument === null || $items[$argument]['id'] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $literal = $items[$argument]['text'];
            $name = $this->decodePhpStringLiteral($literal);
            if ($name === null || $name === '') {
                continue;
            }

            $literalStart = $baseOffset + $items[$argument]['start'] - strlen($prefix);
            $references[] = new TemplateReference(
                TemplateReference::ENGINE_QIQ,
                $name,
                $literalStart + 1,
                $literalStart + strlen($literal) - 1,
            );
        }
    }

    /** @param array{id: int|null, text: string, start: int} $item */
    private function qiqCallName(array $item): ?string
    {
        if ($item['id'] === T_STRING && ($item['text'] === 'render' || $item['text'] === 'setLayout')) {
            return $item['text'];
        }

        // 裸の extends() はPHPではキーワードとしてトークン化される。Qiqコンパイラは
        // これを特別扱いして $this->extends() へ変換する。
        if (($item['id'] === T_EXTENDS || $item['id'] === T_STRING) && $item['text'] === 'extends') {
            return $item['text'];
        }

        return null;
    }

    /**
     * @param list<array{id: int|null, text: string, start: int}> $items
     */
    private function isQiqTemplateCall(array $items, int $index): bool
    {
        $previous = $this->previousSignificant($items, $index);
        if ($previous === null) {
            return true;
        }

        if ($items[$previous]['text'] === '->') {
            $receiver = $this->previousSignificant($items, $previous);

            return $receiver !== null
                && $items[$receiver]['id'] === T_VARIABLE
                && $items[$receiver]['text'] === '$this';
        }

        if ($items[$previous]['text'] === '::') {
            return false;
        }

        return !in_array($items[$previous]['id'], [T_FUNCTION, T_FN, T_NEW], true);
    }

    /**
     * @param list<array{id: int|null, text: string, start: int}> $items
     */
    private function nextSignificant(array $items, int $index): ?int
    {
        for ($index++; $index < count($items); $index++) {
            if ($this->isSignificant($items[$index]['id'])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array{id: int|null, text: string, start: int}> $items
     */
    private function previousSignificant(array $items, int $index): ?int
    {
        for ($index--; $index >= 0; $index--) {
            if ($this->isSignificant($items[$index]['id'])) {
                return $index;
            }
        }

        return null;
    }

    private function isSignificant(?int $tokenId): bool
    {
        return !in_array(
            $tokenId,
            [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG],
            true,
        );
    }

    private function literalPattern(string $name): string
    {
        return '(?<' . $name . '>\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")';
    }

    /**
     * @param array<string, array{0: string, 1: int}|string|int> $match
     */
    private function referenceFromMatch(string $engine, array $match, string $key, int $baseOffset): ?TemplateReference
    {
        if (!isset($match[$key]) || !is_array($match[$key])) {
            return null;
        }

        [$literal, $relativeOffset] = $match[$key];
        $name = $this->decodePhpStringLiteral($literal);
        if ($name === null || $name === '') {
            return null;
        }

        $start = $baseOffset + $relativeOffset + 1;

        return new TemplateReference($engine, $name, $start, $start + strlen($literal) - 2);
    }

    private function decodePhpStringLiteral(string $literal): ?string
    {
        if (strlen($literal) < 2) {
            return null;
        }

        $quote = $literal[0];
        $value = substr($literal, 1, -1);
        if ($quote === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
        }
        if ($quote === '"') {
            return stripcslashes($value);
        }

        return null;
    }
}
