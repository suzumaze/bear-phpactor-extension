<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Template;

use Suzumaze\BearPhpactor\Semantic\Resource\ResourceResolution;

/**
 * Resource/template relationship derived from BEAR's standard layout.
 *
 * Ambiguous resource candidates retain a null template file rather than
 * silently selecting the only candidate that happens to have a template.
 */
final readonly class ResourceTemplateResolution
{
    public function __construct(
        public ResourceResolution $resource,
        public string $engine,
        public ?string $templateFile,
    ) {
    }
}
