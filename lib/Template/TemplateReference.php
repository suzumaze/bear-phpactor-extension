<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Template;

/**
 * テンプレート内に書かれた、静的な別テンプレート参照。
 *
 * start/end はクォートを含まないテンプレート名そのもののバイト範囲。
 */
final class TemplateReference
{
    public const ENGINE_QIQ = 'qiq';
    public const ENGINE_TWIG = 'twig';

    public function __construct(
        public readonly string $engine,
        public readonly string $name,
        public readonly int $start,
        public readonly int $end,
    ) {
    }

    public function contains(int $offset): bool
    {
        return $offset >= $this->start && $offset < $this->end;
    }
}
