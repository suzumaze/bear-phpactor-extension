<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Route;

use Suzumaze\BearPhpactor\Semantic\Resource\ResourceResolution;

/**
 * A route name and the Page resource identity it denotes.
 */
final readonly class RouteResolution
{
    public function __construct(
        public string $routeName,
        public ResourceResolution $resource,
    ) {
    }
}
