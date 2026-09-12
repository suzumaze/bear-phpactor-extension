<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/**
 * Builds a reusable Resource description independently of an LSP transport.
 */
final class ResourceDescriptionQuery
{
    public function __construct(
        private ResourceFactsQuery $resourceFactsQuery = new ResourceFactsQuery(),
        private ResourceIncomingRelationsQuery $incomingRelationsQuery = new ResourceIncomingRelationsQuery(),
    ) {
    }

    /** @return SemanticResult<ResourceDescription|null> */
    public function describeInWorkspace(
        WorkspaceContext $workspace,
        string $uri,
        ?string $contextPath = null,
        int $incomingLimit = ResourceIncomingRelationsQuery::DEFAULT_LIMIT,
    ): SemanticResult {
        $facts = $this->resourceFactsQuery->describeInWorkspace($workspace, $uri, $contextPath);
        if ($facts->status === SemanticStatus::Ok && $facts->value !== null) {
            $incoming = $this->incomingRelationsQuery->findForResolutionInWorkspace(
                $workspace,
                $facts->value->resource,
                $contextPath,
                $incomingLimit,
            );
            if ($incoming->value === null) {
                return SemanticResult::failure($incoming->status);
            }

            return SemanticResult::ok(new ResourceDescription($facts->value, $incoming->value));
        }
        if ($facts->status !== SemanticStatus::Ambiguous) {
            return SemanticResult::failure($facts->status);
        }

        return SemanticResult::ambiguous(array_map(
            static fn (ResourceFacts $candidate): ResourceDescription => new ResourceDescription(
                $candidate,
                ResourceIncomingRelations::unavailable($candidate->resource),
            ),
            $facts->candidates,
        ));
    }
}
