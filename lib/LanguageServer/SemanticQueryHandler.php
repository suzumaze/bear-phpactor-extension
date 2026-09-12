<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\LanguageServer;

use Amp\Promise;
use Amp\Success;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsDescriptorResolution;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFacts;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceInventory;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceInventoryQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceResolution;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Route\RouteQuery;
use Suzumaze\BearPhpactor\Semantic\Route\RouteResolution;
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
    /** @var SemanticResult<WorkspaceContext|null> */
    private SemanticResult $workspace;

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
        private ResourceFactsQuery $resourceFactsQuery = new ResourceFactsQuery(),
    ) {
        $this->workspace = WorkspaceContext::fromRoot($workspaceRoot);
    }

    /** @return array<string,string> */
    public function methods(): array
    {
        return [
            'bear/resource/resolve' => 'resolveResource',
            'bear/resource/list' => 'listResources',
            'bear/resource/describe' => 'describeResource',
            'bear/route/resolve' => 'resolveRoute',
            'bear/sql/resolve' => 'resolveSql',
            'bear/template/resolve' => 'resolveTemplate',
            'bear/template/forResource' => 'resolveResourceTemplate',
            'bear/alps/resolveDescriptor' => 'resolveAlpsDescriptor',
            'bear/schema/resolveNamed' => 'resolveNamedSchema',
            'bear/schema/forResource' => 'resolveResourceSchema',
        ];
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
    ): Promise {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->resourceInventoryQuery->listInWorkspace($workspace, $scheme, $prefix, $limit),
            fn (ResourceInventory $inventory): array => [
                'resources' => array_map($this->resourceData(...), $inventory->resources),
                'total' => $inventory->total,
                'truncated' => $inventory->truncated,
            ],
        ));
    }

    /** @return Promise<array<string,mixed>> */
    public function describeResource(string $uri, ?string $contextPath = null): Promise
    {
        return new Success($this->query(
            fn (WorkspaceContext $workspace): SemanticResult =>
                $this->resourceFactsQuery->describeInWorkspace($workspace, $uri, $contextPath),
            fn (ResourceFacts $facts): array => $this->resourceFactsData($facts),
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
        if ($result->status === SemanticStatus::Ok && $result->value !== null) {
            $data = $normalize($result->value);
        }

        return [
            'status' => $result->status->value,
            'data' => $data,
            'candidates' => array_map($normalize, $result->candidates),
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
                fn ($relation): array => [
                    'kind' => $relation->kind,
                    'rel' => $relation->rel,
                    'sourceUri' => $relation->sourceUri->uri(),
                    'sourceMethod' => $relation->sourceMethod,
                    'targetUri' => $relation->targetUri->uri(),
                    'targetMethod' => $relation->targetMethod,
                    'sourcePath' => $this->relativePath($relation->sourceFile),
                    'byteOffset' => $relation->byteOffset,
                ],
                $facts->outgoingRelations,
            ),
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

    private function relativePath(string $absolutePath): ?string
    {
        if ($this->workspace->value === null) {
            return null;
        }

        return $this->workspace->value->accessPolicy()->inspectExisting($absolutePath)->value?->relative;
    }
}
