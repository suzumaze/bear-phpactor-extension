<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Route;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Resource\Model\ResourceTargetResolver;
use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceResolution;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/**
 * Maps an Aura.Router route name to its BEAR Page resource.
 *
 * HTTP path patterns are deliberately not accepted here: Aura.Router's first
 * argument is the resource name, while its second argument is the incoming URL.
 */
final class RouteQuery
{
    public function __construct(
        private ResourceTargetResolver $resourceTargetResolver = new ResourceTargetResolver(),
        private ResourceQuery $resourceQuery = new ResourceQuery(),
    ) {
    }

    /**
     * @return SemanticResult<RouteResolution|null>
     */
    public function resolve(Project $project, string $routeName): SemanticResult
    {
        $resourceUri = $this->resourceUri($routeName);
        if ($resourceUri === null) {
            return SemanticResult::invalidInput();
        }

        return $this->mapResult(
            $routeName,
            $this->resourceTargetResolver->resolveDetailed($project, $resourceUri),
        );
    }

    /**
     * @return SemanticResult<RouteResolution|null>
     */
    public function resolveInWorkspace(
        WorkspaceContext $workspace,
        string $routeName,
        ?string $contextPath = null,
    ): SemanticResult {
        $resourceUri = $this->resourceUri($routeName);
        if ($resourceUri === null) {
            return SemanticResult::invalidInput();
        }

        return $this->mapResult(
            $routeName,
            $this->resourceQuery->resolveInWorkspace($workspace, $resourceUri->uri(), $contextPath),
        );
    }

    private function resourceUri(string $routeName): ?ResourceUri
    {
        if (!str_starts_with($routeName, '/')) {
            return null;
        }

        return ResourceUri::fromString('page://self' . $routeName);
    }

    /**
     * @param SemanticResult<ResourceResolution|null> $resourceResult
     * @return SemanticResult<RouteResolution|null>
     */
    private function mapResult(string $routeName, SemanticResult $resourceResult): SemanticResult
    {
        if ($resourceResult->status === SemanticStatus::Ok && $resourceResult->value !== null) {
            return SemanticResult::ok(new RouteResolution($routeName, $resourceResult->value));
        }

        if ($resourceResult->status === SemanticStatus::Ambiguous) {
            return SemanticResult::ambiguous(array_map(
                static fn (ResourceResolution $candidate): RouteResolution =>
                    new RouteResolution($routeName, $candidate),
                $resourceResult->candidates,
            ));
        }

        return SemanticResult::failure($resourceResult->status);
    }
}
