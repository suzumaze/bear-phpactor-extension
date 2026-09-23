<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Project;

use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Parser;
use Phpactor\TextDocument\TextDocumentBuilder;
use Suzumaze\BearPhpactor\Alps\AlpsDescriptorAtOffset;
use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaPathResolver;
use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaReferenceAtOffset;
use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use Suzumaze\BearPhpactor\Router\RouteReferenceAtOffset;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsQuery;
use Suzumaze\BearPhpactor\Semantic\Contract\ContractComparison;
use Suzumaze\BearPhpactor\Semantic\Contract\ContractComparisonQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFacts;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceInventory;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceInventoryQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceQuery;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Route\RouteQuery;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaQuery;
use Suzumaze\BearPhpactor\Semantic\Sql\SqlQuery;
use Suzumaze\BearPhpactor\Semantic\Template\TemplateQuery;
use Suzumaze\BearPhpactor\Semantic\Template\TemplateSourceScanner;
use Suzumaze\BearPhpactor\Semantic\Workspace\Psr4PhpSource;
use Suzumaze\BearPhpactor\Semantic\Workspace\Psr4PhpSourceScanner;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Sql\SqlQueryAtOffset;
use Suzumaze\BearPhpactor\Template\TemplateReferenceScanner;
use Throwable;

/**
 * Diagnoses explicit, statically provable project inconsistencies.
 *
 * The query reads only bounded saved files. It never boots the application,
 * evaluates PHP, executes Resources, or runs SQL.
 */
final class ProjectDiagnosticsQuery
{
    public const DEFAULT_LIMIT = 100;

    /** Preserve the public v0.1.7 input range; returned pages also have a byte budget. */
    public const MAX_LIMIT = 200;

    private const MAX_DETAIL_NAMES = 5;

    private const MAX_ROUTE_BYTES = 1_048_576;

    public function __construct(
        private ResourceInventoryQuery $inventoryQuery = new ResourceInventoryQuery(),
        private ResourceFactsQuery $resourceFactsQuery = new ResourceFactsQuery(),
        private ResourceQuery $resourceQuery = new ResourceQuery(),
        private RouteQuery $routeQuery = new RouteQuery(),
        private SqlQuery $sqlQuery = new SqlQuery(),
        private SchemaFactsQuery $schemaFactsQuery = new SchemaFactsQuery(),
        private AlpsQuery $alpsQuery = new AlpsQuery(),
        private TemplateQuery $templateQuery = new TemplateQuery(),
        private ContractComparisonQuery $contractComparisonQuery = new ContractComparisonQuery(),
        private Psr4PhpSourceScanner $phpSourceScanner = new Psr4PhpSourceScanner(),
        private TemplateSourceScanner $templateSourceScanner = new TemplateSourceScanner(),
        private SqlQueryAtOffset $sqlReferenceScanner = new SqlQueryAtOffset(),
        private JsonSchemaReferenceAtOffset $schemaReferenceScanner = new JsonSchemaReferenceAtOffset(),
        private AlpsDescriptorAtOffset $alpsReferenceScanner = new AlpsDescriptorAtOffset(),
        private RouteReferenceAtOffset $routeReferenceScanner = new RouteReferenceAtOffset(),
        private TemplateReferenceScanner $templateReferenceScanner = new TemplateReferenceScanner(),
        private Parser $parser = new Parser(),
    ) {
    }

    /** @return SemanticResult<ProjectDiagnostics|null> */
    public function diagnoseInWorkspace(
        WorkspaceContext $workspace,
        ?string $contextPath = null,
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0,
    ): SemanticResult {
        if ($limit < 1 || $limit > self::MAX_LIMIT || $offset < 0) {
            return SemanticResult::invalidInput();
        }
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }
        $inventory = $this->inventoryQuery->allInWorkspace(
            $workspace,
            contextPath: $contextPath,
        );
        if (!$inventory->value instanceof ResourceInventory) {
            return SemanticResult::failure($inventory->status, $inventory->error, $inventory->provenance);
        }

        $items = [];
        $scannedFiles = [];
        $relationSuppressions = [];
        $sqlRoot = $workspace->accessPolicy()->inspectExisting(
            $project->value->root() . '/' . SqlQuery::CONVENTION_DIRECTORY,
        );
        $scanSqlReferences = $sqlRoot->value !== null && is_dir($sqlRoot->value->absolute);
        $skippedChecks = $scanSqlReferences ? [] : ['sql_references'];
        $schemaRoots = [];
        $schemaDirectories = [
            SchemaQuery::KIND_REQUEST => JsonSchemaPathResolver::REQUEST_SCHEMA_DIR,
            SchemaQuery::KIND_RESPONSE => JsonSchemaPathResolver::RESPONSE_SCHEMA_DIR,
        ];
        foreach ($schemaDirectories as $kind => $directory) {
            $root = $workspace->accessPolicy()->inspectExisting($project->value->root() . '/' . $directory);
            $schemaRoots[$kind] = $root->value !== null && is_dir($root->value->absolute);
            if (!$schemaRoots[$kind]) {
                $skippedChecks[] = $kind . '_schema_references';
            }
        }
        $alpsRoot = $workspace->accessPolicy()->inspectExisting($project->value->root() . '/apidoc.xml');
        $scanAlpsReferences = $alpsRoot->value !== null && is_file($alpsRoot->value->absolute);
        if (!$scanAlpsReferences) {
            $skippedChecks[] = 'alps_descriptors';
        }
        foreach ($this->phpSourceScanner->scan($workspace, $project->value) as $source) {
            $path = $workspace->accessPolicy()->inspectExisting($source->file);
            if ($path->value === null) {
                continue;
            }
            $scannedFiles[$path->value->relative] = true;
            $this->diagnosePhpReferences(
                $workspace,
                $source,
                $path->value->relative,
                $scanSqlReferences,
                $schemaRoots,
                $scanAlpsReferences,
                $items,
            );
        }

        $this->diagnoseRoutes($workspace, $project->value->root(), $items, $scannedFiles);

        foreach ($this->templateSourceScanner->scan($workspace, $project->value) as $source) {
            $path = $workspace->accessPolicy()->inspectExisting($source->file);
            if ($path->value === null) {
                continue;
            }
            $scannedFiles[$path->value->relative] = true;
            $document = TextDocumentBuilder::create($source->contents)
                ->uri($source->file)
                ->language($source->engine)
                ->build();
            try {
                $references = $this->templateReferenceScanner->references($document);
            } catch (Throwable) {
                $references = [];
            }
            foreach ($references as $reference) {
                $result = $this->templateQuery->resolveInWorkspace(
                    $workspace,
                    $reference->engine,
                    $reference->name,
                    $path->value->relative,
                );
                if (!$this->reportableReferenceFailure($result->status)) {
                    continue;
                }
                $items[] = new ProjectDiagnostic(
                    'template_reference_' . $this->statusSuffix($result->status),
                    $result->status,
                    $reference->name,
                    $path->value->relative,
                    $reference->start,
                    $reference->end,
                    ['engine' => $reference->engine],
                );
            }
        }

        foreach ($inventory->value->resources as $resource) {
            $resourcePath = $workspace->accessPolicy()->inspectExisting($resource->file);
            if ($resourcePath->value === null) {
                continue;
            }
            $scannedFiles[$resourcePath->value->relative] = true;
            $facts = $this->resourceFactsQuery->describeResolutionInWorkspace($workspace, $resource);
            if (!$facts->value instanceof ResourceFacts) {
                if (in_array($facts->status, [SemanticStatus::NotFound, SemanticStatus::ParseError], true)) {
                    $items[] = new ProjectDiagnostic(
                        'resource_facts_' . $this->statusSuffix($facts->status),
                        $facts->status,
                        $resource->uri->uri(),
                        $resourcePath->value->relative,
                    );
                }
                continue;
            }

            $this->diagnoseRelations(
                $workspace,
                $facts->value,
                $resourcePath->value->relative,
                $items,
                $relationSuppressions,
            );
            $this->diagnoseContracts($workspace, $facts->value, $resourcePath->value->relative, $items);
        }

        $items = $this->withoutDuplicateRelationReferences($items, $relationSuppressions);

        usort($items, static fn (ProjectDiagnostic $left, ProjectDiagnostic $right): int => [
            $left->path,
            $left->byteStart ?? -1,
            $left->byteEnd ?? -1,
            $left->code,
            $left->subject,
        ] <=> [
            $right->path,
            $right->byteStart ?? -1,
            $right->byteEnd ?? -1,
            $right->code,
            $right->subject,
        ]);

        $total = count($items);
        $selected = ProjectReportPage::slice(
            $items,
            $offset,
            $limit,
            static fn (ProjectDiagnostic $item): int => ProjectReportPage::serializedBytes([
                $item,
                Provenance::savedFile($item->path, $item->byteStart, $item->byteEnd),
            ]),
        );
        $provenance = [Provenance::derived()];
        $composer = $workspace->accessPolicy()->inspectExisting($project->value->root() . '/composer.json');
        if ($composer->value !== null) {
            $provenance[] = Provenance::savedFile($composer->value->relative);
        }
        foreach ($selected as $item) {
            $provenance[] = Provenance::savedFile($item->path, $item->byteStart, $item->byteEnd);
        }

        return SemanticResult::ok(
            new ProjectDiagnostics(
                $selected,
                $total,
                $offset,
                $offset + count($selected) < $total,
                count($scannedFiles),
                count($inventory->value->resources),
                $inventory->value->truncated,
                $skippedChecks,
            ),
            $provenance,
        );
    }

    /** @param array<string,bool> $schemaRoots @param list<ProjectDiagnostic> $items */
    private function diagnosePhpReferences(
        WorkspaceContext $workspace,
        Psr4PhpSource $source,
        string $relativePath,
        bool $scanSqlReferences,
        array $schemaRoots,
        bool $scanAlpsReferences,
        array &$items,
    ): void {
        try {
            $root = $this->parser->parseSourceFile($source->contents, $source->file);
            foreach ($root->getDescendantNodes() as $node) {
                if (!$node instanceof StringLiteral) {
                    continue;
                }
                $opening = substr($source->contents, $node->getStartPosition(), 1);
                if ($opening !== "'" && $opening !== '"') {
                    continue;
                }
                $uri = ResourceUri::fromString($node->getStringContentsText());
                if ($uri === null) {
                    continue;
                }
                $result = $this->resourceQuery->resolveInWorkspace($workspace, $uri->uri(), $relativePath);
                if (!$this->reportableReferenceFailure($result->status)) {
                    continue;
                }
                $items[] = new ProjectDiagnostic(
                    'resource_reference_' . $this->statusSuffix($result->status),
                    $result->status,
                    $uri->uri(),
                    $relativePath,
                    $node->getStartPosition() + 1,
                    $node->getEndPosition() - 1,
                    $result->status === SemanticStatus::Ambiguous
                        ? ['candidateCount' => count($result->candidates)]
                        : [],
                );
            }
        } catch (Throwable) {
            // Resource files are diagnosed through ResourceFactsQuery below.
        }

        $document = TextDocumentBuilder::create($source->contents)
            ->uri($source->file)
            ->language('php')
            ->build();
        if ($scanSqlReferences) {
            try {
                foreach ($this->sqlReferenceScanner->references($document) as [$start, $queryId, $end]) {
                    $result = $this->sqlQuery->resolveInWorkspace($workspace, $queryId, $relativePath);
                    if (!$this->reportableReferenceFailure($result->status)) {
                        continue;
                    }
                    $items[] = new ProjectDiagnostic(
                        'sql_reference_' . $this->statusSuffix($result->status),
                        $result->status,
                        $queryId,
                        $relativePath,
                        $start,
                        $end,
                    );
                }
            } catch (Throwable) {
            }
        }
        try {
            foreach ($this->schemaReferenceScanner->references($document) as [$start, $name, $end, $kind]) {
                if (!($schemaRoots[$kind] ?? false)) {
                    continue;
                }
                $result = $this->schemaFactsQuery->describeNamedInWorkspace(
                    $workspace,
                    $name,
                    $kind,
                    $relativePath,
                );
                if (!$this->reportableReferenceFailure($result->status)) {
                    continue;
                }
                $items[] = new ProjectDiagnostic(
                    'schema_reference_' . $this->statusSuffix($result->status),
                    $result->status,
                    $name,
                    $relativePath,
                    $start,
                    $end,
                    ['kind' => $kind],
                );
            }
        } catch (Throwable) {
        }
        if ($scanAlpsReferences) {
            try {
                foreach ($this->alpsReferenceScanner->references($document) as [$start, $descriptorId, $end]) {
                    $result = $this->alpsQuery->resolveInWorkspace($workspace, $descriptorId, $relativePath);
                    if (!$this->reportableReferenceFailure($result->status)) {
                        continue;
                    }
                    $items[] = new ProjectDiagnostic(
                        'alps_descriptor_' . $this->statusSuffix($result->status),
                        $result->status,
                        $descriptorId,
                        $relativePath,
                        $start,
                        $end,
                        $result->status === SemanticStatus::Ambiguous
                            ? ['candidateCount' => count($result->candidates)]
                            : [],
                    );
                }
            } catch (Throwable) {
            }
        }
    }

    /** @param list<ProjectDiagnostic> $items @param array<string,true> $scannedFiles */
    private function diagnoseRoutes(
        WorkspaceContext $workspace,
        string $projectRoot,
        array &$items,
        array &$scannedFiles,
    ): void {
        $routePath = $workspace->accessPolicy()->inspectExisting($projectRoot . '/aura.route.php');
        if ($routePath->value === null || !is_file($routePath->value->absolute)) {
            return;
        }
        $contents = @file_get_contents($routePath->value->absolute, false, null, 0, self::MAX_ROUTE_BYTES + 1);
        if ($contents === false || strlen($contents) > self::MAX_ROUTE_BYTES) {
            return;
        }
        $scannedFiles[$routePath->value->relative] = true;
        $document = TextDocumentBuilder::create($contents)
            ->uri($routePath->value->absolute)
            ->language('php')
            ->build();
        try {
            $references = $this->routeReferenceScanner->references($document);
        } catch (Throwable) {
            $references = [];
        }
        foreach ($references as [$start, $routeName, $end]) {
            $result = $this->routeQuery->resolveInWorkspace($workspace, $routeName, $routePath->value->relative);
            if (!$this->reportableReferenceFailure($result->status)) {
                continue;
            }
            $items[] = new ProjectDiagnostic(
                'route_resource_' . $this->statusSuffix($result->status),
                $result->status,
                $routeName,
                $routePath->value->relative,
                $start,
                $end,
                $result->status === SemanticStatus::Ambiguous
                    ? ['candidateCount' => count($result->candidates)]
                    : [],
            );
        }
    }

    /**
     * @param list<ProjectDiagnostic> $items
     * @param array<string,list<array{subject:string,start:int,end:int}>> $relationSuppressions
     */
    private function diagnoseRelations(
        WorkspaceContext $workspace,
        ResourceFacts $facts,
        string $relativePath,
        array &$items,
        array &$relationSuppressions,
    ): void {
        foreach ($facts->outgoingRelations as $relation) {
            [$rangeStart, $rangeEnd] = $this->relationAttributeRange($facts, $relation->byteOffset);
            $target = $this->resourceQuery->resolveInWorkspace(
                $workspace,
                $relation->targetUri->uri(),
                $relativePath,
            );
            if ($this->reportableReferenceFailure($target->status)) {
                $relationSuppressions[$relativePath][] = [
                    'subject' => $relation->targetUri->uri(),
                    'start' => $rangeStart,
                    'end' => $rangeEnd,
                ];
                $items[] = new ProjectDiagnostic(
                    'relation_target_' . $this->statusSuffix($target->status),
                    $target->status,
                    $relation->targetUri->uri(),
                    $relativePath,
                    $rangeStart,
                    $rangeEnd,
                    [
                        'kind' => $relation->kind,
                        'rel' => $relation->rel,
                        'sourceMethod' => $relation->sourceMethod,
                    ],
                );
                continue;
            }
            if ($target->status !== SemanticStatus::Ok || $target->value === null || $relation->targetMethod === null) {
                continue;
            }
            $targetFacts = $this->resourceFactsQuery->describeResolutionInWorkspace($workspace, $target->value);
            if (!$targetFacts->value instanceof ResourceFacts) {
                continue;
            }
            $methodNames = array_map(static fn ($method): string => $method->name, $targetFacts->value->methods);
            if (in_array($relation->targetMethod, $methodNames, true)) {
                continue;
            }
            $items[] = new ProjectDiagnostic(
                'relation_method_not_found',
                SemanticStatus::NotFound,
                $relation->targetUri->uri() . '#' . $relation->targetMethod,
                $relativePath,
                $rangeStart,
                $rangeEnd,
                [
                    'kind' => $relation->kind,
                    'rel' => $relation->rel,
                    'sourceMethod' => $relation->sourceMethod,
                    'targetMethod' => $relation->targetMethod,
                ],
            );
        }
    }

    /** @return array{int,int} */
    private function relationAttributeRange(ResourceFacts $facts, int $byteOffset): array
    {
        foreach ($facts->attributes as $attribute) {
            if (
                $attribute->byteStart === $byteOffset
                && in_array($attribute->name, ['Embed', 'Link'], true)
            ) {
                return [$attribute->byteStart, $attribute->byteEnd];
            }
        }

        return [$byteOffset, $byteOffset];
    }

    /**
     * Prefer the richer relation diagnostic over the generic URI literal report.
     *
     * @param list<ProjectDiagnostic> $items
     * @param array<string,list<array{subject:string,start:int,end:int}>> $relationSuppressions
     * @return list<ProjectDiagnostic>
     */
    private function withoutDuplicateRelationReferences(array $items, array $relationSuppressions): array
    {
        return array_values(array_filter(
            $items,
            static function (ProjectDiagnostic $item) use ($relationSuppressions): bool {
                if (
                    !str_starts_with($item->code, 'resource_reference_')
                    || $item->byteStart === null
                    || $item->byteEnd === null
                ) {
                    return true;
                }
                foreach ($relationSuppressions[$item->path] ?? [] as $suppression) {
                    if (
                        $item->subject === $suppression['subject']
                        && $item->byteStart >= $suppression['start']
                        && $item->byteEnd <= $suppression['end']
                    ) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /** @param list<ProjectDiagnostic> $items */
    private function diagnoseContracts(
        WorkspaceContext $workspace,
        ResourceFacts $facts,
        string $relativePath,
        array &$items,
    ): void {
        foreach ($facts->methods as $method) {
            foreach ([SchemaQuery::KIND_REQUEST, SchemaQuery::KIND_RESPONSE] as $kind) {
                $result = $this->contractComparisonQuery->compareFactsInWorkspace(
                    $workspace,
                    $facts,
                    $method->name,
                    $kind,
                );
                if (!$result->value instanceof ContractComparison || $result->value->comparison === null) {
                    continue;
                }
                $comparison = $result->value->comparison;
                if (
                    $comparison->onlyInResource === []
                    && $comparison->onlyInSchema === []
                    && $comparison->onlyInAlps === []
                ) {
                    continue;
                }
                $items[] = new ProjectDiagnostic(
                    'contract_name_mismatch',
                    SemanticStatus::Ok,
                    $facts->resource->uri->uri() . '#' . $method->name . ':' . $kind,
                    $relativePath,
                    details: [
                        'comparison' => 'exact_name_presence_only',
                        'compared' => $comparison->compared,
                        'onlyInResource' => array_slice($comparison->onlyInResource, 0, self::MAX_DETAIL_NAMES),
                        'onlyInSchema' => array_slice($comparison->onlyInSchema, 0, self::MAX_DETAIL_NAMES),
                        'onlyInAlps' => array_slice($comparison->onlyInAlps, 0, self::MAX_DETAIL_NAMES),
                        'nameTotals' => [
                            'resource' => count($comparison->onlyInResource),
                            'schema' => count($comparison->onlyInSchema),
                            'alps' => count($comparison->onlyInAlps),
                        ],
                        'detailsTruncated' => count($comparison->onlyInResource) > self::MAX_DETAIL_NAMES
                            || count($comparison->onlyInSchema) > self::MAX_DETAIL_NAMES
                            || count($comparison->onlyInAlps) > self::MAX_DETAIL_NAMES,
                    ],
                );
            }
        }
    }

    private function reportableReferenceFailure(SemanticStatus $status): bool
    {
        return in_array($status, [
            SemanticStatus::NotFound,
            SemanticStatus::Ambiguous,
            SemanticStatus::InvalidInput,
            SemanticStatus::ParseError,
        ], true);
    }

    private function statusSuffix(SemanticStatus $status): string
    {
        return $status === SemanticStatus::ParseError ? 'malformed' : $status->value;
    }
}
