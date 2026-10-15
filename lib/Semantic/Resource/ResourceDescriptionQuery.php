<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaQuery;
use Suzumaze\BearPhpactor\Semantic\Schema\ResourceSchemaQuery;
use Suzumaze\BearPhpactor\Semantic\Template\ResourceTemplateQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Template\TemplateReference;

/**
 * Builds a reusable Resource description independently of an LSP transport.
 */
final class ResourceDescriptionQuery
{
    public function __construct(
        private ResourceFactsQuery $resourceFactsQuery = new ResourceFactsQuery(),
        private ResourceIncomingRelationsQuery $incomingRelationsQuery = new ResourceIncomingRelationsQuery(),
        private ResourceTemplateQuery $resourceTemplateQuery = new ResourceTemplateQuery(),
        private SchemaQuery $schemaQuery = new SchemaQuery(),
    ) {
    }

    /** @return SemanticResult<ResourceDescription|null> */
    public function describeInWorkspace(
        WorkspaceContext $workspace,
        string $uri,
        ?string $contextPath = null,
        int $incomingLimit = ResourceIncomingRelationsQuery::DEFAULT_LIMIT,
        ?string $applicationContext = null,
    ): SemanticResult {
        if ($incomingLimit < 1 || $incomingLimit > ResourceIncomingRelationsQuery::MAX_LIMIT) {
            return SemanticResult::invalidInput();
        }

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

            return $this->description($workspace, $facts->value, $incoming->value, $applicationContext);
        }
        if ($facts->status !== SemanticStatus::Ambiguous) {
            return SemanticResult::failure($facts->status);
        }

        $candidates = [];
        foreach ($facts->candidates as $candidate) {
            $description = $this->description(
                $workspace,
                $candidate,
                ResourceIncomingRelations::unavailable($candidate->resource),
                $applicationContext,
            );
            if ($description->value === null) {
                return SemanticResult::failure($description->status);
            }
            $candidates[] = $description->value;
        }

        return SemanticResult::ambiguous($candidates);
    }

    /** @return SemanticResult<ResourceDescription|null> */
    private function description(
        WorkspaceContext $workspace,
        ResourceFacts $facts,
        ResourceIncomingRelations $incoming,
        ?string $applicationContext,
    ): SemanticResult {
        $templates = [];
        foreach ([TemplateReference::ENGINE_QIQ, TemplateReference::ENGINE_TWIG] as $engine) {
            $template = $this->resourceTemplateQuery->resolveForResolutionInWorkspace(
                $workspace,
                $facts->resource,
                $engine,
            );
            if ($template->status === SemanticStatus::Ok && $template->value !== null) {
                $templates[] = $template->value;
                continue;
            }
            if ($template->status !== SemanticStatus::NotFound) {
                return SemanticResult::failure($template->status);
            }
        }

        $schemas = [];
        $schemaCoverage = [];
        $responseSchema = null;
        $discovery = new ResourceSchemaQuery($this->schemaQuery);
        foreach ($facts->methods as $method) {
            foreach ([SchemaQuery::KIND_REQUEST, SchemaQuery::KIND_RESPONSE] as $kind) {
                $selection = $discovery->select($workspace, $facts, $method->name, $kind, $applicationContext);
                $schema = $selection['result'];
                $schemaCoverage[] = [
                    'method' => $method->name,
                    'kind' => $kind,
                    'selection' => $selection['selection'],
                    'status' => $schema->status->value,
                    'directorySelection' => $schema->value->directorySelection
                        ?? $schema->coverage['schemaDirectories'] ?? null,
                    'candidates' => array_map(static fn ($candidate) => [
                        'directorySelection' => $candidate->directorySelection,
                    ], $schema->candidates),
                ];
                if ($schema->value !== null) {
                    $schemas[] = $schema->value;
                    if ($kind === SchemaQuery::KIND_RESPONSE && $responseSchema === null) {
                        $responseSchema = $schema->value;
                    }
                } elseif (
                    !in_array($schema->status, [
                        SemanticStatus::NotFound, SemanticStatus::Unsupported, SemanticStatus::Ambiguous,
                    ], true)
                ) {
                    return SemanticResult::failure($schema->status);
                }
            }
        }

        $provenance = [Provenance::derived()];
        $this->addFileProvenance($workspace, $provenance, $facts->resource->file);
        foreach ($incoming->relations as $relation) {
            $this->addFileProvenance(
                $workspace,
                $provenance,
                $relation->sourceFile,
                $relation->byteOffset,
                $relation->byteOffset,
            );
        }
        foreach ($templates as $template) {
            if ($template->templateFile !== null) {
                $this->addFileProvenance($workspace, $provenance, $template->templateFile);
            }
        }
        foreach ($schemas as $schema) {
            if ($schema->file !== null) {
                $this->addFileProvenance($workspace, $provenance, $schema->file);
            }
        }

        return SemanticResult::ok(
            new ResourceDescription($facts, $incoming, $templates, $responseSchema, $schemas, $schemaCoverage),
            $provenance,
        );
    }

    /** @param list<Provenance> $provenance */
    private function addFileProvenance(
        WorkspaceContext $workspace,
        array &$provenance,
        string $file,
        ?int $byteStart = null,
        ?int $byteEnd = null,
    ): void {
        $path = $workspace->accessPolicy()->inspectExisting($file);
        if ($path->value !== null) {
            $provenance[] = Provenance::savedFile($path->value->relative, $byteStart, $byteEnd);
        }
    }
}
