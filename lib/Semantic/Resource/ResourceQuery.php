<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Suzumaze\BearPhpactor\Resource\Model\ImportAppRegistry;
use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceAccessPolicy;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/**
 * Resolves BEAR resource identities without depending on LSP positions or
 * locations. Transport adapters decide how each semantic status is exposed.
 */
final class ResourceQuery
{
    /**
     * Headless entry point with an explicit workspace and optional source
     * context. Caller-supplied paths are always workspace-relative.
     *
     * @return SemanticResult<ResourceResolution|null>
     */
    public function resolveInWorkspace(
        WorkspaceContext $workspace,
        string $uri,
        ?string $contextPath = null,
    ): SemanticResult {
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        $result = $this->resolveString($project->value, $uri);

        return $this->enforceWorkspace($workspace->accessPolicy(), $result);
    }

    /**
     * @return SemanticResult<ResourceResolution|null>
     */
    public function resolveString(Project $project, string $uri): SemanticResult
    {
        $resourceUri = ResourceUri::fromString($uri);
        if ($resourceUri === null) {
            return SemanticResult::invalidInput();
        }

        return $this->resolve($project, $resourceUri);
    }

    /**
     * @return SemanticResult<ResourceResolution|null>
     */
    public function resolve(Project $project, ResourceUri $uri): SemanticResult
    {
        if ($this->containsParentSegment($uri)) {
            return SemanticResult::invalidInput();
        }

        if ($uri->host() !== 'self') {
            $target = ImportAppRegistry::forProject($project->root())->resolve($uri);
            if ($target === null) {
                return SemanticResult::notFound();
            }

            return SemanticResult::ok($this->resolution($uri, $target));
        }

        $file = $project->classFile($uri);
        $fqn = $project->classFqn($uri);
        if ($file !== null && $fqn !== null && is_file($file)) {
            return SemanticResult::ok(new ResourceResolution($uri, $file, $fqn));
        }

        $candidates = array_map(
            fn (array $candidate): ResourceResolution => $this->resolution($uri, $candidate),
            $project->classFileCandidates($uri),
        );
        usort(
            $candidates,
            static fn (ResourceResolution $left, ResourceResolution $right): int =>
                [$left->file, $left->fqn] <=> [$right->file, $right->fqn],
        );

        if ($candidates === []) {
            return SemanticResult::notFound();
        }

        if (count($candidates) > 1) {
            return SemanticResult::ambiguous($candidates);
        }

        return SemanticResult::ok($candidates[0]);
    }

    /**
     * @param array{file: string, fqn: string} $target
     */
    private function resolution(ResourceUri $uri, array $target): ResourceResolution
    {
        return new ResourceResolution($uri, $target['file'], $target['fqn']);
    }

    private function containsParentSegment(ResourceUri $uri): bool
    {
        foreach (explode('/', $uri->path()) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param SemanticResult<ResourceResolution|null> $result
     * @return SemanticResult<ResourceResolution|null>
     */
    private function enforceWorkspace(
        WorkspaceAccessPolicy $policy,
        SemanticResult $result,
    ): SemanticResult {
        if ($result->status === SemanticStatus::Ok && $result->value !== null) {
            $path = $policy->inspectExisting($result->value->file);
            if ($path->value === null) {
                return SemanticResult::failure($path->status);
            }

            return SemanticResult::ok(new ResourceResolution(
                $result->value->uri,
                $path->value->absolute,
                $result->value->fqn,
            ));
        }

        if ($result->status !== SemanticStatus::Ambiguous) {
            return $result;
        }

        $candidates = [];
        foreach ($result->candidates as $candidate) {
            $path = $policy->inspectExisting($candidate->file);
            if ($path->value === null) {
                return SemanticResult::failure($path->status);
            }

            $key = $path->value->absolute;
            $resolution = new ResourceResolution(
                $candidate->uri,
                $path->value->absolute,
                $candidate->fqn,
            );
            if (!isset($candidates[$key]) || $resolution->fqn < $candidates[$key]->fqn) {
                $candidates[$key] = $resolution;
            }
        }
        ksort($candidates, SORT_STRING);
        $candidates = array_values($candidates);

        if (count($candidates) === 1) {
            return SemanticResult::ok($candidates[0]);
        }

        return SemanticResult::ambiguous($candidates);
    }
}
