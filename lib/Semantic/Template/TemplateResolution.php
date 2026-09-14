<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Template;

/**
 * Transport-independent result of resolving a static template reference.
 */
final readonly class TemplateResolution
{
    public function __construct(
        public string $engine,
        public string $name,
        public string $file,
    ) {
    }
}
