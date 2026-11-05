<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

/**
 * The body a Resource class declares in its own docblock (`@property array{...} $body`).
 *
 * This is what the author wrote, not what the code was proven to produce. Each union member is one
 * variant: an array shape with string keys lists them with their types as written (class names are
 * not resolved), anything else keeps only its type text. Parents and traits are not read.
 */
final readonly class ResourceDeclaredBody
{
    public const REASON_UNREADABLE_DOCBLOCK = 'unreadable_docblock';

    /**
     * @param list<array{keys: list<array{name: string, type: string, optional: bool}>, open?: true}
     *     |array{type: string}> $variants
     */
    private function __construct(
        public string $tag,
        public int $line,
        public bool $readable,
        public array $variants,
        public ?string $reason,
    ) {
    }

    /**
     * @param list<array{keys: list<array{name: string, type: string, optional: bool}>, open?: true}
     *     |array{type: string}> $variants
     */
    public static function declared(string $tag, int $line, array $variants): self
    {
        return new self($tag, $line, true, $variants, null);
    }

    public static function unreadable(string $tag, int $line): self
    {
        return new self($tag, $line, false, [], self::REASON_UNREADABLE_DOCBLOCK);
    }

    public function hasKeyedVariant(): bool
    {
        foreach ($this->variants as $variant) {
            if (isset($variant['keys'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first variant whose keys fit these exact top-level names: every required key is present,
     * and no name is missing from the variant unless the shape is open (`array{a: int, ...}`).
     *
     * @param list<string> $names
     */
    public function matchingVariant(array $names): ?int
    {
        foreach ($this->variants as $index => $variant) {
            if (!isset($variant['keys'])) {
                continue;
            }
            $declared = array_column($variant['keys'], 'name');
            $required = array_column(array_filter(
                $variant['keys'],
                static fn (array $key): bool => !$key['optional'],
            ), 'name');
            if (
                array_diff($required, $names) === []
                && (isset($variant['open']) || array_diff($names, $declared) === [])
            ) {
                return $index;
            }
        }

        return null;
    }
}
