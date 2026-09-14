<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Alps;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/**
 * Resolves an ALPS semantic descriptor through apidoc.xml without booting the app.
 */
final class AlpsQuery
{
    public function __construct(
        private AlpsProfileQuery $profileQuery = new AlpsProfileQuery(),
    ) {
    }

    /**
     * @return SemanticResult<AlpsDescriptorResolution|null>
     */
    public function resolve(Project $project, string $descriptorId): SemanticResult
    {
        if ($descriptorId === '' || str_contains($descriptorId, "\0")) {
            return SemanticResult::invalidInput();
        }

        $profile = $this->profileQuery->load($project);
        if ($profile->value === null) {
            return SemanticResult::failure($profile->status);
        }
        $descriptors = $profile->value->descriptorsById($descriptorId);
        if ($descriptors === []) {
            return SemanticResult::notFound();
        }

        $resolutions = [];
        foreach ($descriptors as $descriptor) {
            if ($descriptor->offset === null) {
                return SemanticResult::parseError();
            }
            $resolutions[] = new AlpsDescriptorResolution(
                $descriptorId,
                $profile->value->file,
                $descriptor->offset,
            );
        }

        return count($resolutions) === 1
            ? SemanticResult::ok($resolutions[0])
            : SemanticResult::ambiguous($resolutions);
    }

    /**
     * @return SemanticResult<AlpsDescriptorResolution|null>
     */
    public function resolveInWorkspace(
        WorkspaceContext $workspace,
        string $descriptorId,
        ?string $contextPath = null,
    ): SemanticResult {
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        $result = $this->resolve($project->value, $descriptorId);
        if ($result->status === SemanticStatus::Ok && $result->value !== null) {
            return $this->enforceWorkspace($workspace, $result->value);
        }
        if ($result->status !== SemanticStatus::Ambiguous) {
            return $result;
        }

        $candidates = [];
        foreach ($result->candidates as $candidate) {
            $checked = $this->workspaceResolution($workspace, $candidate);
            if ($checked->value === null) {
                return SemanticResult::failure($checked->status);
            }
            $candidates[] = $checked->value;
        }

        return SemanticResult::ambiguous($candidates);
    }

    /** @return SemanticResult<AlpsDescriptorResolution|null> */
    private function enforceWorkspace(
        WorkspaceContext $workspace,
        AlpsDescriptorResolution $resolution,
    ): SemanticResult {
        return $this->workspaceResolution($workspace, $resolution);
    }

    /** @return SemanticResult<AlpsDescriptorResolution|null> */
    private function workspaceResolution(
        WorkspaceContext $workspace,
        AlpsDescriptorResolution $resolution,
    ): SemanticResult {
        $path = $workspace->accessPolicy()->inspectExisting($resolution->profileFile);
        if ($path->value === null) {
            return SemanticResult::failure($path->status);
        }

        return SemanticResult::ok(
            new AlpsDescriptorResolution(
                $resolution->descriptorId,
                $path->value->absolute,
                $resolution->offset,
            ),
            [Provenance::savedFile($path->value->relative, $resolution->offset, $resolution->offset)],
        );
    }
}
