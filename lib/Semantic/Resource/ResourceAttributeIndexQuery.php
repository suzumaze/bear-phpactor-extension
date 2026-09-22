<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/**
 * Builds a bounded index without making one malformed Resource hide the rest.
 */
final class ResourceAttributeIndexQuery
{
    public function __construct(
        private ResourceInventoryQuery $inventoryQuery = new ResourceInventoryQuery(),
        private ResourceFactsQuery $factsQuery = new ResourceFactsQuery(),
    ) {
    }

    /** @return SemanticResult<ResourceAttributeIndex|null> */
    public function listInWorkspace(
        WorkspaceContext $workspace,
        ?string $scheme = null,
        string $prefix = '',
        int $limit = ResourceInventoryQuery::DEFAULT_LIMIT,
        int $offset = 0,
    ): SemanticResult {
        $inventory = $this->inventoryQuery->listInWorkspace(
            $workspace,
            $scheme,
            $prefix,
            $limit,
            offset: $offset,
        );
        if (!$inventory->value instanceof ResourceInventory) {
            return SemanticResult::failure($inventory->status);
        }

        $items = [];
        $provenance = $inventory->provenance;
        foreach ($inventory->value->resources as $resource) {
            $facts = $this->factsQuery->describeResolutionInWorkspace($workspace, $resource);
            $items[] = new ResourceAttributeIndexItem(
                $resource,
                $facts->status,
                $facts->value instanceof ResourceFacts ? $facts->value->attributes : [],
            );
            if ($facts->status === SemanticStatus::Ok) {
                array_push($provenance, ...$facts->provenance);
            }
        }

        return SemanticResult::ok(
            new ResourceAttributeIndex(
                $items,
                $inventory->value->total,
                $inventory->value->offset,
                $inventory->value->truncated,
            ),
            $provenance,
        );
    }
}
