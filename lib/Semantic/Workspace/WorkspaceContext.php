<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Workspace;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;

/**
 * Fixed root and access policy for one headless semantic session.
 */
final readonly class WorkspaceContext
{
    private function __construct(
        private WorkspaceAccessPolicy $accessPolicy,
    ) {
    }

    /**
     * @return SemanticResult<self|null>
     */
    public static function fromRoot(string $root): SemanticResult
    {
        $policy = WorkspaceAccessPolicy::fromRoot($root);
        if ($policy->value === null) {
            return SemanticResult::failure($policy->status);
        }

        return SemanticResult::ok(new self($policy->value));
    }

    public function root(): string
    {
        return $this->accessPolicy->root();
    }

    public function accessPolicy(): WorkspaceAccessPolicy
    {
        return $this->accessPolicy;
    }

    /**
     * Locate the BEAR project at the workspace root or around a relative
     * context path, never walking above the fixed workspace boundary.
     *
     * @return SemanticResult<Project|null>
     */
    public function project(?string $contextPath = null): SemanticResult
    {
        if ($contextPath === null) {
            $project = Project::fromRoot($this->root());

            return $project === null ? SemanticResult::notFound() : SemanticResult::ok($project);
        }

        $path = $this->accessPolicy->resolveExisting($contextPath);
        if ($path->value === null) {
            return SemanticResult::failure($path->status);
        }

        $project = Project::locateWithin($path->value->absolute, $this->root());

        return $project === null ? SemanticResult::notFound() : SemanticResult::ok($project);
    }
}
