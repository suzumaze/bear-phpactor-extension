<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/**
 * Finds method-level Link/Embed relations that target a Resource.
 *
 * The complete Resource inventory is scanned to calculate `total`; only the
 * returned payload is bounded. This keeps truncation explicit instead of
 * presenting a partial scan as a complete relationship graph.
 */
final class ResourceIncomingRelationsQuery
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public function __construct(
        private ResourceQuery $resourceQuery = new ResourceQuery(),
        private ResourceFactsQuery $resourceFactsQuery = new ResourceFactsQuery(),
        private ?ResourceInventoryIndex $inventoryIndex = null,
    ) {
    }

    /** @return SemanticResult<ResourceIncomingRelations|null> */
    public function findInWorkspace(
        WorkspaceContext $workspace,
        string $uri,
        ?string $contextPath = null,
        int $limit = self::DEFAULT_LIMIT,
    ): SemanticResult {
        if (!$this->validLimit($limit)) {
            return SemanticResult::invalidInput();
        }

        $resource = $this->resourceQuery->resolveInWorkspace($workspace, $uri, $contextPath);
        if ($resource->status === SemanticStatus::Ok && $resource->value !== null) {
            return $this->findForResolutionInWorkspace($workspace, $resource->value, $contextPath, $limit);
        }
        if ($resource->status !== SemanticStatus::Ambiguous) {
            return SemanticResult::failure($resource->status);
        }

        return SemanticResult::ambiguous(array_map(
            ResourceIncomingRelations::unavailable(...),
            $resource->candidates,
        ));
    }

    /** @return SemanticResult<ResourceIncomingRelations|null> */
    public function findForResolutionInWorkspace(
        WorkspaceContext $workspace,
        ResourceResolution $target,
        ?string $contextPath = null,
        int $limit = self::DEFAULT_LIMIT,
    ): SemanticResult {
        if (!$this->validLimit($limit)) {
            return SemanticResult::invalidInput();
        }

        $targetPath = $workspace->accessPolicy()->inspectExisting($target->file);
        if ($targetPath->value === null) {
            return SemanticResult::failure($targetPath->status);
        }
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        $relations = [];
        $candidates = $this->inventoryIndex === null
            ? $project->value->resourceClassCandidates()
            : $this->inventoryIndex->candidates($project->value, $contextPath);
        foreach ($candidates as $candidate) {
            $sourceUri = ResourceUri::fromString($candidate['uri']);
            if ($sourceUri === null) {
                continue;
            }
            $sourcePath = $workspace->accessPolicy()->inspectExisting($candidate['file']);
            if ($sourcePath->value === null) {
                return SemanticResult::failure($sourcePath->status);
            }

            $facts = $this->resourceFactsQuery->describeResolutionInWorkspace(
                $workspace,
                new ResourceResolution($sourceUri, $sourcePath->value->absolute, $candidate['fqn']),
            );
            if ($facts->value === null) {
                return SemanticResult::failure($facts->status);
            }
            foreach ($facts->value->outgoingRelations as $relation) {
                if ($relation->targetUri->uri() === $target->uri->uri()) {
                    $relations[] = $relation;
                }
            }
        }

        usort($relations, self::compareRelations(...));
        $total = count($relations);

        $selected = array_slice($relations, 0, $limit);
        $provenance = [Provenance::savedFile($targetPath->value->relative), Provenance::derived()];
        foreach ($selected as $relation) {
            $path = $workspace->accessPolicy()->inspectExisting($relation->sourceFile);
            if ($path->value !== null) {
                $provenance[] = Provenance::savedFile(
                    $path->value->relative,
                    $relation->byteOffset,
                    $relation->byteOffset,
                );
            }
        }

        return SemanticResult::ok(
            new ResourceIncomingRelations(
                new ResourceResolution($target->uri, $targetPath->value->absolute, $target->fqn),
                true,
                $selected,
                $total,
                $total > $limit,
            ),
            $provenance,
        );
    }

    private static function compareRelations(ResourceRelationFact $left, ResourceRelationFact $right): int
    {
        return [
            $left->sourceUri->uri(),
            $left->sourceFile,
            $left->sourceMethod,
            $left->byteOffset,
            $left->kind,
            $left->rel,
            $left->targetUri->uri(),
            $left->targetMethod ?? '',
        ] <=> [
            $right->sourceUri->uri(),
            $right->sourceFile,
            $right->sourceMethod,
            $right->byteOffset,
            $right->kind,
            $right->rel,
            $right->targetUri->uri(),
            $right->targetMethod ?? '',
        ];
    }

    private function validLimit(int $limit): bool
    {
        return $limit >= 1 && $limit <= self::MAX_LIMIT;
    }
}
