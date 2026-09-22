<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\LanguageServer;

use Amp\Promise;
use Amp\Success;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsDescriptorFacts;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsDescriptorRelationFact;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsDescriptorResolution;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsQuery;
use Suzumaze\BearPhpactor\Semantic\Contract\ContractComparison;
use Suzumaze\BearPhpactor\Semantic\Contract\ContractComparisonQuery;
use Suzumaze\BearPhpactor\Semantic\Project\ContractCoverage;
use Suzumaze\BearPhpactor\Semantic\Project\ContractCoverageItem;
use Suzumaze\BearPhpactor\Semantic\Project\ContractCoverageQuery;
use Suzumaze\BearPhpactor\Semantic\Project\ContractCoverageSurface;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectInfo;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectInfoQuery;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectDiagnostic;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectDiagnostics;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectDiagnosticsQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceDescription;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceDescriptionQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceAttributeFact;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceAttributeIndex;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceAttributeIndexQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFacts;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceIncomingRelations;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceIncomingRelationsQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceInventory;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceInventoryQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceReference;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceReferences;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceReferencesQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceRelationFact;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceResolution;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Route\RouteQuery;
use Suzumaze\BearPhpactor\Semantic\Route\RouteResolution;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaFacts;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaQuery;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaResolution;
use Suzumaze\BearPhpactor\Semantic\Sql\SqlQuery;
use Suzumaze\BearPhpactor\Semantic\Sql\SqlResolution;
use Suzumaze\BearPhpactor\Semantic\Template\ResourceTemplateQuery;
use Suzumaze\BearPhpactor\Semantic\Template\ResourceTemplateResolution;
use Suzumaze\BearPhpactor\Semantic\Template\TemplateQuery;
use Suzumaze\BearPhpactor\Semantic\Template\TemplateResolution;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Phpactor\LanguageServer\Core\Handler\Handler;

/**
 * Thin read-only LSP adapter for semantic queries that have no positional
 * standard-LSP equivalent. Standard position-based methods remain primary.
 */
final class SemanticQueryHandler implements Handler
{
    /**
     * Version of the public bear/* custom-request contract.
     *
     * This is independent of the LSP protocol version and the package version.
     */
    public const SEMANTIC_API_VERSION = 1;

    /** @var SemanticResult<WorkspaceContext|null> */
    private SemanticResult $workspace;
    private ResourceDescriptionQuery $resourceDescriptionQuery;
    private ResourceFactsQuery $resourceFactsQuery;

    public function __construct(
        string $workspaceRoot,
        private ResourceQuery $resourceQuery = new ResourceQuery(),
        private RouteQuery $routeQuery = new RouteQuery(),
        private SqlQuery $sqlQuery = new SqlQuery(),
        private TemplateQuery $templateQuery = new TemplateQuery(),
        private ResourceTemplateQuery $resourceTemplateQuery = new ResourceTemplateQuery(),
        private AlpsQuery $alpsQuery = new AlpsQuery(),
        private SchemaQuery $schemaQuery = new SchemaQuery(),
        private ResourceInventoryQuery $resourceInventoryQuery = new ResourceInventoryQuery(),
        ResourceFactsQuery $resourceFactsQuery = new ResourceFactsQuery(),
        private ResourceIncomingRelationsQuery $resourceIncomingRelationsQuery = new ResourceIncomingRelationsQuery(),
        ?ResourceDescriptionQuery $resourceDescriptionQuery = null,
        private ProjectInfoQuery $projectInfoQuery = new ProjectInfoQuery(),
        private SchemaFactsQuery $schemaFactsQuery = new SchemaFactsQuery(),
        private AlpsFactsQuery $alpsFactsQuery = new AlpsFactsQuery(),
        private ResourceReferencesQuery $resourceReferencesQuery = new ResourceReferencesQuery(),
        private ResourceAttributeIndexQuery $resourceAttributeIndexQuery = new ResourceAttributeIndexQuery(),
        private ContractComparisonQuery $contractComparisonQuery = new ContractComparisonQuery(),
        private ProjectDiagnosticsQuery $projectDiagnosticsQuery = new ProjectDiagnosticsQuery(),
        private ContractCoverageQuery $contractCoverageQuery = new ContractCoverageQuery(),
    ) {
        $this->workspace = WorkspaceContext::fromRoot($workspaceRoot);
        $this->resourceFactsQuery = $resourceFactsQuery;
        $this->resourceDescriptionQuery = $resourceDescriptionQuery ?? new ResourceDescriptionQuery(
            $resourceFactsQuery,
            $this->resourceIncomingRelationsQuery,
        );
    }

    /** @return array<string,string> */
    public function methods(): array
    {
        return [
            'bear/project/info' => 'describeProject',
            'bear/project/diagnostics' => 'diagnoseProject',
            'bear/project/contractCoverage' => 'inspectContractCoverage',
            'bear/resource/resolve' => 'resolveResource',
            'bear/resource/list' => 'listResources',
            'bear/resource/describe' => 'describeResource',
            'bear/resource/attributes' => 'describeResourceAttributes',
            'bear/resource/attributeIndex' => 'indexResourceAttributes',
            'bear/resource/incomingRelations' => 'findIncomingResourceRelations',
            'bear/resource/references' => 'findResourceReferences',
            'bear/contract/compare' => 'compareContract',
            'bear/route/resolve' => 'resolveRoute',
            'bear/sql/resolve' => 'resolveSql',
            'bear/template/resolve' => 'resolveTemplate',
            'bear/template/forResource' => 'resolveResourceTemplate',
            'bear/alps/resolveDescriptor' => 'resolveAlpsDescriptor',
            'bear/alps/describeDescriptor' => 'describeAlpsDescriptor',
            'bear/schema/resolveNamed' => 'resolveNamedSchema',
            'bear/schema/forResource' => 'resolveResourceSchema',
            'bear/schema/describeNamed' => 'describeNamedSchema',
            'bear/schema/describeForResource' => 'describeResourceSchema',
        ];
    }

    /** @return Promise<array<string,mixed>> */
    public function describeProject(?string $contextPath = null): Promise
    {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->projectInfoQuery->describeInWorkspace($workspace, $contextPath),
            fn (ProjectInfo $info): array => [
                'semanticApiVersion' => self::SEMANTIC_API_VERSION,
                'workspaceName' => $info->workspaceName,
                'projectPath' => $info->projectPath,
                'composerPath' => $info->composerPath,
                'psr4Roots' => array_map(
                    static fn ($root): array => [
                        'namespace' => $root->namespace,
                        'path' => $root->path,
                    ],
                    $info->psr4Roots,
                ),
                'excludedPsr4Roots' => $info->excludedPsr4Roots,
                'resourceCount' => $info->resourceCount,
                'capabilities' => $info->capabilities,
                'versions' => $info->versions,
                'compatibilityIssues' => array_map(
                    static fn ($issue): array => [
                        'code' => $issue->code,
                        'packages' => $issue->packages,
                    ],
                    $info->compatibilityIssues,
                ),
            ],
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function diagnoseProject(
        ?string $contextPath = null,
        int $limit = ProjectDiagnosticsQuery::DEFAULT_LIMIT,
        int $offset = 0,
    ): Promise {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->projectDiagnosticsQuery->diagnoseInWorkspace($workspace, $contextPath, $limit, $offset),
            fn (ProjectDiagnostics $diagnostics): array => [
                'items' => array_map($this->projectDiagnosticData(...), $diagnostics->items),
                'total' => $diagnostics->total,
                'offset' => $diagnostics->offset,
                'truncated' => $diagnostics->truncated,
                'scannedFiles' => $diagnostics->scannedFiles,
                'scannedResources' => $diagnostics->scannedResources,
                'resourceScanTruncated' => $diagnostics->resourceScanTruncated,
                'skippedChecks' => $diagnostics->skippedChecks,
            ],
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function inspectContractCoverage(
        ?string $contextPath = null,
        int $limit = ContractCoverageQuery::DEFAULT_LIMIT,
        int $offset = 0,
        bool $gapsOnly = false,
        ?string $scheme = null,
    ): Promise {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->contractCoverageQuery->inspectInWorkspace(
                    $workspace,
                    $contextPath,
                    $limit,
                    $offset,
                    $gapsOnly,
                    $scheme,
                ),
            fn (ContractCoverage $coverage): array => [
                'items' => array_map($this->contractCoverageItemData(...), $coverage->items),
                'total' => $coverage->total,
                'matchingTotal' => $coverage->matchingTotal,
                'offset' => $coverage->offset,
                'truncated' => $coverage->truncated,
                'gapsOnly' => $coverage->gapsOnly,
                'scheme' => $coverage->scheme,
                'scannedResources' => $coverage->scannedResources,
                'analyzedResources' => $coverage->analyzedResources,
                'resourceScanTruncated' => $coverage->resourceScanTruncated,
                'summary' => $coverage->summary,
            ],
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function resolveResource(string $uri, ?string $contextPath = null): Promise
    {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->resourceQuery->resolveInWorkspace($workspace, $uri, $contextPath),
            fn (ResourceResolution $resolution): array => $this->resourceData($resolution),
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function listResources(
        ?string $scheme = null,
        string $prefix = '',
        int $limit = ResourceInventoryQuery::DEFAULT_LIMIT,
        int $offset = 0,
    ): Promise {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->resourceInventoryQuery->listInWorkspace(
                    $workspace,
                    $scheme,
                    $prefix,
                    $limit,
                    offset: $offset,
                ),
            fn (ResourceInventory $inventory): array => [
                'resources' => array_map($this->resourceData(...), $inventory->resources),
                'total' => $inventory->total,
                'offset' => $inventory->offset,
                'truncated' => $inventory->truncated,
            ],
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function describeResource(
        string $uri,
        ?string $contextPath = null,
        int $incomingLimit = ResourceIncomingRelationsQuery::DEFAULT_LIMIT,
    ): Promise {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->resourceDescriptionQuery->describeInWorkspace(
                    $workspace,
                    $uri,
                    $contextPath,
                    $incomingLimit,
                ),
            fn (ResourceDescription $description): array => $this->resourceDescriptionData($description),
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function describeResourceAttributes(string $uri, ?string $contextPath = null): Promise
    {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->resourceFactsQuery->describeInWorkspace($workspace, $uri, $contextPath),
            fn (ResourceFacts $facts): array => [
                'resource' => $this->resourceData($facts->resource),
                'attributes' => array_map($this->resourceAttributeData(...), $facts->attributes),
                'argumentPolicy' => $this->attributeArgumentPolicy(),
            ],
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function indexResourceAttributes(
        ?string $scheme = null,
        string $prefix = '',
        int $limit = ResourceInventoryQuery::DEFAULT_LIMIT,
        int $offset = 0,
    ): Promise {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->resourceAttributeIndexQuery->listInWorkspace(
                    $workspace,
                    $scheme,
                    $prefix,
                    $limit,
                    $offset,
                ),
            fn (ResourceAttributeIndex $index): array => [
                'items' => array_map(
                    fn ($item): array => [
                        'resource' => $this->resourceData($item->resource),
                        'status' => $item->status->value,
                        'attributes' => array_map($this->resourceAttributeData(...), $item->attributes),
                    ],
                    $index->items,
                ),
                'total' => $index->total,
                'offset' => $index->offset,
                'truncated' => $index->truncated,
                'argumentPolicy' => $this->attributeArgumentPolicy(),
            ],
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function findIncomingResourceRelations(
        string $uri,
        ?string $contextPath = null,
        int $limit = ResourceIncomingRelationsQuery::DEFAULT_LIMIT,
    ): Promise {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->resourceIncomingRelationsQuery->findInWorkspace($workspace, $uri, $contextPath, $limit),
            fn (ResourceIncomingRelations $relations): array => [
                'resource' => $this->resourceData($relations->resource),
                ...$this->incomingRelationsData($relations),
            ],
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function findResourceReferences(
        string $uri,
        ?string $contextPath = null,
        int $limit = ResourceReferencesQuery::DEFAULT_LIMIT,
    ): Promise {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->resourceReferencesQuery->findInWorkspace($workspace, $uri, $contextPath, $limit),
            fn (ResourceReferences $references): array => [
                'resource' => $this->resourceData($references->resource),
                'references' => array_map(
                    $this->resourceReferenceData(...),
                    $references->references,
                ),
                'total' => $references->total,
                'truncated' => $references->truncated,
            ],
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function compareContract(
        string $uri,
        string $method = 'onGet',
        string $schemaKind = SchemaQuery::KIND_RESPONSE,
        ?string $descriptorId = null,
        ?string $contextPath = null,
    ): Promise {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->contractComparisonQuery->compareInWorkspace(
                    $workspace,
                    $uri,
                    $method,
                    $schemaKind,
                    $descriptorId,
                    $contextPath,
                ),
            fn (ContractComparison $comparison): array => $this->contractComparisonData($comparison),
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function resolveRoute(string $route, ?string $contextPath = null): Promise
    {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->routeQuery->resolveInWorkspace($workspace, $route, $contextPath),
            fn (RouteResolution $resolution): array => [
                'route' => $resolution->routeName,
                'resource' => $this->resourceData($resolution->resource),
            ],
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function resolveSql(string $queryId, ?string $contextPath = null): Promise
    {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->sqlQuery->resolveInWorkspace($workspace, $queryId, $contextPath),
            fn (SqlResolution $resolution): array => [
                'queryId' => $resolution->queryId,
                'path' => $this->relativePath($resolution->file),
            ],
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function resolveTemplate(
        string $engine,
        string $name,
        ?string $contextPath = null,
    ): Promise {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->templateQuery->resolveInWorkspace($workspace, $engine, $name, $contextPath),
            fn (TemplateResolution $resolution): array => [
                'engine' => $resolution->engine,
                'name' => $resolution->name,
                'path' => $this->relativePath($resolution->file),
            ],
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function resolveResourceTemplate(
        string $uri,
        string $engine,
        ?string $contextPath = null,
    ): Promise {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->resourceTemplateQuery->resolveInWorkspace($workspace, $uri, $engine, $contextPath),
            fn (ResourceTemplateResolution $resolution): array => [
                'resource' => $this->resourceData($resolution->resource),
                'engine' => $resolution->engine,
                'path' => $resolution->templateFile === null
                    ? null
                    : $this->relativePath($resolution->templateFile),
                'searched' => array_values(array_filter(array_map(
                    $this->relativeCandidatePath(...),
                    $resolution->searchedFiles,
                ), static fn (?string $path): bool => $path !== null)),
            ],
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function resolveAlpsDescriptor(string $descriptorId, ?string $contextPath = null): Promise
    {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->alpsQuery->resolveInWorkspace($workspace, $descriptorId, $contextPath),
            fn (AlpsDescriptorResolution $resolution): array => [
                'descriptorId' => $resolution->descriptorId,
                'profilePath' => $this->relativePath($resolution->profileFile),
                'byteOffset' => $resolution->offset,
            ],
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function describeAlpsDescriptor(string $descriptorId, ?string $contextPath = null): Promise
    {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->alpsFactsQuery->describeInWorkspace($workspace, $descriptorId, $contextPath),
            fn (AlpsDescriptorFacts $facts): array => $this->alpsDescriptorFactsData($facts),
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function resolveNamedSchema(
        string $fileName,
        string $kind,
        ?string $contextPath = null,
    ): Promise {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->schemaQuery->resolveNamedInWorkspace($workspace, $fileName, $kind, $contextPath),
            fn (SchemaResolution $resolution): array => $this->schemaData($resolution),
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function resolveResourceSchema(
        string $uri,
        string $kind = SchemaQuery::KIND_RESPONSE,
        ?string $contextPath = null,
    ): Promise {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->schemaQuery->resolveForResourceInWorkspace($workspace, $uri, $kind, $contextPath),
            fn (SchemaResolution $resolution): array => $this->schemaData($resolution),
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function describeNamedSchema(
        string $fileName,
        string $kind,
        ?string $contextPath = null,
    ): Promise {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->schemaFactsQuery->describeNamedInWorkspace($workspace, $fileName, $kind, $contextPath),
            fn (SchemaFacts $facts): array => $this->schemaFactsData($facts),
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function describeResourceSchema(
        string $uri,
        string $kind = SchemaQuery::KIND_RESPONSE,
        ?string $contextPath = null,
    ): Promise {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->schemaFactsQuery->describeForResourceInWorkspace($workspace, $uri, $kind, $contextPath),
            fn (SchemaFacts $facts): array => $this->schemaFactsData($facts),
        ));
    }

    /**
     * @template TValue
     * @param callable(WorkspaceContext): SemanticResult<TValue|null> $query
     * @param callable(TValue): array<string,mixed> $normalize
     * @return array<string,mixed>
     */
    private function query(callable $query, callable $normalize): array
    {
        if ($this->workspace->value === null) {
            return $this->envelope(SemanticResult::failure($this->workspace->status), $normalize);
        }

        return $this->envelope($query($this->workspace->value), $normalize);
    }

    /**
     * @template TValue
     * @param SemanticResult<TValue|null> $result
     * @param callable(TValue): array<string,mixed> $normalize
     * @return array<string,mixed>
     */
    private function envelope(SemanticResult $result, callable $normalize): array
    {
        $data = null;
        if ($result->value !== null) {
            $data = $normalize($result->value);
        }

        $envelope = [
            'status' => $result->status->value,
            'data' => $data,
            'candidates' => array_map($normalize, $result->candidates),
            'provenance' => array_map($this->provenanceData(...), $result->provenance),
        ];
        if ($result->partial !== null) {
            $envelope['partial'] = $normalize($result->partial);
        }
        if ($result->error !== null) {
            $envelope['error'] = [
                'code' => $result->error->code,
                'message' => $result->error->message,
            ];
        }

        return $envelope;
    }

    /** @return array<string,mixed> */
    private function provenanceData(Provenance $provenance): array
    {
        $data = ['source' => $provenance->source];
        if ($provenance->path !== null) {
            $data['path'] = $provenance->path;
        }
        $data['freshness'] = $provenance->freshness->value;
        if ($provenance->byteStart !== null && $provenance->byteEnd !== null) {
            $data['byteRange'] = [
                'start' => $provenance->byteStart,
                'end' => $provenance->byteEnd,
            ];
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function projectDiagnosticData(ProjectDiagnostic $diagnostic): array
    {
        $data = [
            'code' => $diagnostic->code,
            'status' => $diagnostic->status->value,
            'subject' => $diagnostic->subject,
            'path' => $diagnostic->path,
        ];
        if ($diagnostic->byteStart !== null && $diagnostic->byteEnd !== null) {
            $data['byteRange'] = [
                'start' => $diagnostic->byteStart,
                'end' => $diagnostic->byteEnd,
            ];
        }
        $data['details'] = $diagnostic->details;

        return $data;
    }

    /** @return array<string,mixed> */
    private function contractCoverageItemData(ContractCoverageItem $item): array
    {
        return [
            'uri' => $item->uri,
            'method' => $item->method,
            'path' => $item->path,
            'covered' => $item->covered(),
            'gaps' => $item->gaps,
            'surfaces' => [
                'requestSchema' => $this->contractCoverageSurfaceData($item->requestSchema),
                'responseSchema' => $this->contractCoverageSurfaceData($item->responseSchema),
                'alps' => $this->contractCoverageSurfaceData($item->alps),
            ],
        ];
    }

    /** @return array{state:string,status:string,subject:?string} */
    private function contractCoverageSurfaceData(ContractCoverageSurface $surface): array
    {
        return [
            'state' => $surface->state,
            'status' => $surface->status->value,
            'subject' => $surface->subject,
        ];
    }

    /** @return array{uri:string,fqn:string,path:?string} */
    private function resourceData(ResourceResolution $resolution): array
    {
        return [
            'uri' => $resolution->uri->uri(),
            'fqn' => $resolution->fqn,
            'path' => $this->relativePath($resolution->file),
        ];
    }

    /** @return array<string,mixed> */
    private function resourceFactsData(ResourceFacts $facts): array
    {
        return [
            'resource' => $this->resourceData($facts->resource),
            'methods' => array_map(
                static fn ($method): array => [
                    'name' => $method->name,
                    'parameters' => array_map(
                        static fn ($parameter): array => [
                            'name' => $parameter->name,
                            'type' => $parameter->type,
                        ],
                        $method->parameters,
                    ),
                ],
                $facts->methods,
            ),
            'relationsOut' => array_map(
                $this->relationData(...),
                $facts->outgoingRelations,
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function resourceDescriptionData(ResourceDescription $description): array
    {
        return [
            ...$this->resourceFactsData($description->facts),
            'relationsIn' => $this->incomingRelationsData($description->incomingRelations),
            'templates' => array_map(
                fn (ResourceTemplateResolution $template): array => [
                    'engine' => $template->engine,
                    'path' => $template->templateFile === null
                        ? null
                        : $this->relativePath($template->templateFile),
                ],
                $description->templates,
            ),
            'schemas' => $description->responseSchema === null
                ? []
                : [$this->schemaData($description->responseSchema)],
        ];
    }

    /** @return array{available:bool,items:list<array<string,mixed>>,total:int,truncated:bool} */
    private function incomingRelationsData(ResourceIncomingRelations $incoming): array
    {
        return [
            'available' => $incoming->available,
            'items' => array_map($this->relationData(...), $incoming->relations),
            'total' => $incoming->total,
            'truncated' => $incoming->truncated,
        ];
    }

    /** @return array<string,mixed> */
    private function relationData(ResourceRelationFact $relation): array
    {
        return [
            'kind' => $relation->kind,
            'rel' => $relation->rel,
            'sourceUri' => $relation->sourceUri->uri(),
            'sourceMethod' => $relation->sourceMethod,
            'targetUri' => $relation->targetUri->uri(),
            'targetMethod' => $relation->targetMethod,
            'sourcePath' => $this->relativePath($relation->sourceFile),
            'byteOffset' => $relation->byteOffset,
        ];
    }

    /** @return array<string,mixed> */
    private function resourceAttributeData(ResourceAttributeFact $attribute): array
    {
        return [
            'target' => $attribute->target,
            'method' => $attribute->methodName,
            'name' => $attribute->name,
            'fqn' => $attribute->fqn,
            'arguments' => array_map(
                static fn ($argument): array => [
                    'name' => $argument->name,
                    'type' => $argument->valueType,
                    'value' => $argument->value,
                ],
                $attribute->arguments,
            ),
            'byteRange' => [
                'start' => $attribute->byteStart,
                'end' => $attribute->byteEnd,
            ],
        ];
    }

    /** @return array{source:string,constructorDefaultsExpanded:bool} */
    private function attributeArgumentPolicy(): array
    {
        return [
            'source' => 'explicit_only',
            'constructorDefaultsExpanded' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function contractComparisonData(ContractComparison $comparison): array
    {
        return [
            'resource' => $this->resourceData($comparison->resource),
            'method' => $comparison->method,
            'schemaKind' => $comparison->schemaKind,
            'surfaces' => array_map(
                static fn ($surface): array => [
                    'source' => $surface->source,
                    'status' => $surface->status->value,
                    'subject' => $surface->subject,
                    'names' => $surface->names,
                ],
                $comparison->surfaces,
            ),
            'comparison' => $comparison->comparison === null ? null : [
                'compared' => $comparison->comparison->compared,
                'common' => $comparison->comparison->common,
                'onlyInResource' => $comparison->comparison->onlyInResource,
                'onlyInSchema' => $comparison->comparison->onlyInSchema,
                'onlyInAlps' => $comparison->comparison->onlyInAlps,
                'presence' => array_map(
                    static fn ($presence): array => [
                        'name' => $presence->name,
                        'sources' => $presence->sources,
                    ],
                    $comparison->comparison->presence,
                ),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function resourceReferenceData(ResourceReference $reference): array
    {
        return [
            'kind' => $reference->kind,
            'identifier' => $reference->identifier,
            'path' => $this->relativePath($reference->file),
            'byteRange' => [
                'start' => $reference->contentStart,
                'end' => $reference->contentEnd,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function schemaData(SchemaResolution $resolution): array
    {
        return [
            'kind' => $resolution->kind,
            'source' => $resolution->source,
            'path' => $resolution->file === null ? null : $this->relativePath($resolution->file),
            'titleByteOffset' => $resolution->titleOffset,
            'resource' => $resolution->resource === null ? null : $this->resourceData($resolution->resource),
        ];
    }

    /** @return array<string,mixed> */
    private function schemaFactsData(SchemaFacts $facts): array
    {
        return [
            ...$this->schemaData($facts->schema),
            'available' => $facts->available,
            'types' => $facts->types,
            'properties' => array_map(
                static fn ($property): array => [
                    'name' => $property->name,
                    'required' => $property->required,
                    'types' => $property->types,
                ],
                $facts->properties,
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function alpsDescriptorFactsData(AlpsDescriptorFacts $facts): array
    {
        $descriptor = $facts->descriptor;

        return [
            'descriptorId' => $descriptor->resolution->descriptorId,
            'profilePath' => $this->relativePath($descriptor->resolution->profileFile),
            'byteOffset' => $descriptor->resolution->offset,
            'type' => $descriptor->type,
            'name' => $descriptor->name,
            'rt' => $descriptor->rt,
            'href' => $descriptor->href,
            'rel' => $descriptor->rel,
            'doc' => $descriptor->doc,
            'def' => $descriptor->def,
            'tag' => $descriptor->tag,
            'title' => $descriptor->title,
            'relationsOut' => array_map($this->alpsRelationData(...), $facts->outgoingRelations),
            'relationsIn' => array_map($this->alpsRelationData(...), $facts->incomingRelations),
        ];
    }

    /** @return array<string,mixed> */
    private function alpsRelationData(AlpsDescriptorRelationFact $relation): array
    {
        return [
            'kind' => $relation->kind,
            'sourceId' => $relation->sourceId,
            'targetId' => $relation->targetId,
            'targetStatus' => $relation->targetStatus->value,
            'sourceByteOffset' => $relation->sourceOffset,
            'targetByteOffset' => $relation->targetOffset,
        ];
    }

    private function relativePath(string $absolutePath): ?string
    {
        if ($this->workspace->value === null) {
            return null;
        }

        return $this->workspace->value->accessPolicy()->inspectExisting($absolutePath)->value?->relative;
    }

    private function relativeCandidatePath(string $absolutePath): ?string
    {
        if ($this->workspace->value === null) {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', $this->workspace->value->root()), '/');
        $path = str_replace('\\', '/', $absolutePath);
        if ($path === $root) {
            return '';
        }
        if (!str_starts_with($path, $root . '/')) {
            return null;
        }

        return substr($path, strlen($root) + 1);
    }
}
