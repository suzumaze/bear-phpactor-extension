<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Template;

final readonly class TemplateSource
{
    public function __construct(
        public string $file,
        public string $contents,
        public string $engine,
    ) {
    }
}
