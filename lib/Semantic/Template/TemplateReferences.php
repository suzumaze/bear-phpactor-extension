<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Template;

/**
 * A resolved template identity and all static references found for it.
 */
final readonly class TemplateReferences
{
    /** @param list<TemplateReferenceUsage> $references */
    public function __construct(
        public TemplateResolution $template,
        public array $references,
    ) {
    }
}
