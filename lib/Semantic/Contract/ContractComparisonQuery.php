<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Contract;

use Suzumaze\BearPhpactor\Semantic\Alps\AlpsDescriptorFacts;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsDescriptorRelationFact;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceAttributeFact;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFacts;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceMethodFact;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaFacts;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/**
 * Compares exact field-name presence without claiming type or meaning equality.
 */
final class ContractComparisonQuery
{
    public function __construct(
        private ResourceFactsQuery $resourceFactsQuery = new ResourceFactsQuery(),
        private SchemaFactsQuery $schemaFactsQuery = new SchemaFactsQuery(),
        private AlpsFactsQuery $alpsFactsQuery = new AlpsFactsQuery(),
    ) {
    }

    /** @return SemanticResult<ContractComparison|null> */
    public function compareInWorkspace(
        WorkspaceContext $workspace,
        string $uri,
        string $method = 'onGet',
        string $schemaKind = SchemaQuery::KIND_RESPONSE,
        ?string $descriptorId = null,
        ?string $contextPath = null,
    ): SemanticResult {
        if (
            $method === ''
            || str_contains($method, "\0")
            || !in_array($schemaKind, [SchemaQuery::KIND_REQUEST, SchemaQuery::KIND_RESPONSE], true)
            || ($descriptorId !== null && ($descriptorId === '' || str_contains($descriptorId, "\0")))
        ) {
            return SemanticResult::invalidInput();
        }

        $facts = $this->resourceFactsQuery->describeInWorkspace($workspace, $uri, $contextPath);
        if ($facts->status === SemanticStatus::Ok && $facts->value instanceof ResourceFacts) {
            return $this->compareFacts($workspace, $facts->value, $method, $schemaKind, $descriptorId)
                ->withProvenance($facts->provenance);
        }
        if ($facts->status !== SemanticStatus::Ambiguous) {
            return SemanticResult::failure($facts->status, $facts->error, $facts->provenance);
        }

        $candidates = [];
        foreach ($facts->candidates as $candidate) {
            $comparison = $this->compareFacts($workspace, $candidate, $method, $schemaKind, $descriptorId);
            if (!$comparison->value instanceof ContractComparison) {
                return SemanticResult::failure($comparison->status, $comparison->error, $comparison->provenance);
            }
            $candidates[] = $comparison->value;
        }

        return SemanticResult::ambiguous($candidates);
    }

    /**
     * Compare a Resource whose saved-source facts have already been parsed.
     *
     * Project-wide queries use this entry point to avoid resolving and parsing
     * the same Resource once per method and contract direction.
     *
     * @return SemanticResult<ContractComparison|null>
     */
    public function compareFactsInWorkspace(
        WorkspaceContext $workspace,
        ResourceFacts $facts,
        string $method = 'onGet',
        string $schemaKind = SchemaQuery::KIND_RESPONSE,
        ?string $descriptorId = null,
    ): SemanticResult {
        if (
            $method === ''
            || str_contains($method, "\0")
            || !in_array($schemaKind, [SchemaQuery::KIND_REQUEST, SchemaQuery::KIND_RESPONSE], true)
            || ($descriptorId !== null && ($descriptorId === '' || str_contains($descriptorId, "\0")))
        ) {
            return SemanticResult::invalidInput();
        }

        return $this->compareFacts($workspace, $facts, $method, $schemaKind, $descriptorId);
    }

    /** @return SemanticResult<ContractComparison|null> */
    private function compareFacts(
        WorkspaceContext $workspace,
        ResourceFacts $facts,
        string $method,
        string $schemaKind,
        ?string $descriptorId,
    ): SemanticResult {
        $resourceSurface = $this->resourceSurface($facts, $method, $schemaKind);
        [$schemaSurface, $schemaProvenance] = $this->schemaSurface($workspace, $facts, $method, $schemaKind);
        [$alpsSurface, $alpsProvenance] = $this->alpsSurface(
            $workspace,
            $facts,
            $method,
            $schemaKind,
            $descriptorId,
        );
        $surfaces = [$resourceSurface, $schemaSurface, $alpsSurface];

        return SemanticResult::ok(
            new ContractComparison(
                $facts->resource,
                $method,
                $schemaKind,
                $surfaces,
                $this->compareSurfaces($surfaces),
            ),
            [Provenance::derived(), ...$schemaProvenance, ...$alpsProvenance],
        );
    }

    private function resourceSurface(ResourceFacts $facts, string $method, string $schemaKind): ContractSurface
    {
        if ($schemaKind === SchemaQuery::KIND_RESPONSE) {
            return new ContractSurface('resource', SemanticStatus::Unsupported, $method . ':body', []);
        }

        foreach ($facts->methods as $candidate) {
            if ($candidate->name !== $method) {
                continue;
            }

            return new ContractSurface(
                'resource',
                SemanticStatus::Ok,
                $method . ':parameters',
                $this->sortedNames(array_map(
                    static fn ($parameter): string => $parameter->name,
                    $candidate->parameters,
                )),
            );
        }

        return new ContractSurface('resource', SemanticStatus::NotFound, $method . ':parameters', []);
    }

    /** @return array{ContractSurface,list<Provenance>} */
    private function schemaSurface(
        WorkspaceContext $workspace,
        ResourceFacts $facts,
        string $method,
        string $schemaKind,
    ): array {
        $schemaName = $this->schemaName($facts, $method, $schemaKind);
        if ($schemaName !== null) {
            $result = $this->schemaFactsQuery->describeNamedInWorkspace(
                $workspace,
                $schemaName,
                $schemaKind,
                $this->relativeResourcePath($workspace, $facts),
            );
            $subject = $schemaKind . ':' . $schemaName;
        } elseif ($this->hasSchemaArgument($facts, $method, $schemaKind)) {
            return [new ContractSurface('schema', SemanticStatus::Unsupported, $schemaKind . ':dynamic', []), []];
        } elseif ($schemaKind === SchemaQuery::KIND_RESPONSE) {
            $result = $this->schemaFactsQuery->describeForResolutionInWorkspace(
                $workspace,
                $facts->resource,
                $schemaKind,
            );
            $subject = 'response:convention';
        } else {
            return [new ContractSurface('schema', SemanticStatus::Unsupported, 'request', []), []];
        }

        if (!$result->value instanceof SchemaFacts) {
            return [new ContractSurface('schema', $result->status, $subject, []), $result->provenance];
        }
        if (!$result->value->available) {
            return [new ContractSurface('schema', SemanticStatus::NotFound, $subject, []), $result->provenance];
        }

        return [
            new ContractSurface(
                'schema',
                SemanticStatus::Ok,
                $subject,
                $this->sortedNames(array_map(
                    static fn ($property): string => $property->name,
                    $result->value->properties,
                )),
            ),
            $result->provenance,
        ];
    }

    /** @return array{ContractSurface,list<Provenance>} */
    private function alpsSurface(
        WorkspaceContext $workspace,
        ResourceFacts $facts,
        string $method,
        string $schemaKind,
        ?string $descriptorId,
    ): array {
        $descriptorId ??= $this->alpsDescriptorId($facts, $method);
        if ($descriptorId === null) {
            if ($this->attributesForMethod($facts, $method, 'Alps') !== []) {
                return [new ContractSurface('alps', SemanticStatus::Unsupported, 'dynamic', []), []];
            }

            return [new ContractSurface('alps', SemanticStatus::NotFound, null, []), []];
        }

        $result = $this->alpsFactsQuery->describeInWorkspace(
            $workspace,
            $descriptorId,
            $this->relativeResourcePath($workspace, $facts),
        );
        if (!$result->value instanceof AlpsDescriptorFacts) {
            return [new ContractSurface('alps', $result->status, $descriptorId, []), $result->provenance];
        }

        $provenance = $result->provenance;
        $surfaceFacts = $result->value;
        $surfaceId = $descriptorId;
        if ($schemaKind === SchemaQuery::KIND_RESPONSE) {
            $representationId = $this->localAlpsId($surfaceFacts->descriptor->rt);
            if ($surfaceFacts->descriptor->rt !== null && $representationId === null) {
                return [
                    new ContractSurface('alps', SemanticStatus::Unsupported, $descriptorId . ':external-rt', []),
                    $provenance,
                ];
            }
            if ($representationId !== null) {
                $representation = $this->alpsFactsQuery->describeInWorkspace(
                    $workspace,
                    $representationId,
                    $this->relativeResourcePath($workspace, $facts),
                );
                array_push($provenance, ...$representation->provenance);
                if (!$representation->value instanceof AlpsDescriptorFacts) {
                    return [
                        new ContractSurface('alps', $representation->status, $representationId, []),
                        $provenance,
                    ];
                }
                $surfaceFacts = $representation->value;
                $surfaceId = $representationId;
            }
        }

        $names = [];
        foreach ($surfaceFacts->outgoingRelations as $relation) {
            if (
                $relation->kind === AlpsDescriptorRelationFact::KIND_CONTAINS
                && $relation->targetStatus === SemanticStatus::Ok
            ) {
                $names[] = $relation->targetId;
            }
        }

        return [
            new ContractSurface('alps', SemanticStatus::Ok, $surfaceId, $this->sortedNames($names)),
            $provenance,
        ];
    }

    private function schemaName(ResourceFacts $facts, string $method, string $schemaKind): ?string
    {
        foreach ($this->attributesForMethod($facts, $method, 'JsonSchema') as $attribute) {
            foreach ($attribute->arguments as $index => $argument) {
                $matches = $schemaKind === SchemaQuery::KIND_REQUEST
                    ? $argument->name === 'params'
                    : $argument->name === 'schema' || ($index === 0 && $argument->name === null);
                if ($matches && $argument->valueType === 'string' && is_string($argument->value)) {
                    return $argument->value;
                }
            }
        }

        return null;
    }

    private function hasSchemaArgument(ResourceFacts $facts, string $method, string $schemaKind): bool
    {
        foreach ($this->attributesForMethod($facts, $method, 'JsonSchema') as $attribute) {
            foreach ($attribute->arguments as $index => $argument) {
                if ($schemaKind === SchemaQuery::KIND_REQUEST && $argument->name === 'params') {
                    return true;
                }
                if (
                    $schemaKind === SchemaQuery::KIND_RESPONSE
                    && ($argument->name === 'schema' || ($index === 0 && $argument->name === null))
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function alpsDescriptorId(ResourceFacts $facts, string $method): ?string
    {
        foreach ($this->attributesForMethod($facts, $method, 'Alps') as $attribute) {
            $argument = $attribute->arguments[0] ?? null;
            if (
                $argument !== null
                && $argument->name === null
                && $argument->valueType === 'string'
                && is_string($argument->value)
            ) {
                return $argument->value;
            }
        }

        return null;
    }

    /** @return list<ResourceAttributeFact> */
    private function attributesForMethod(ResourceFacts $facts, string $method, string $name): array
    {
        $methodAttributes = [];
        $classAttributes = [];
        foreach ($facts->attributes as $attribute) {
            if ($attribute->name !== $name) {
                continue;
            }
            if ($attribute->target === 'method' && $attribute->methodName === $method) {
                $methodAttributes[] = $attribute;
            } elseif ($attribute->target === 'class') {
                $classAttributes[] = $attribute;
            }
        }

        return [...$methodAttributes, ...$classAttributes];
    }

    /** @param list<ContractSurface> $surfaces */
    private function compareSurfaces(array $surfaces): ?ContractNameComparison
    {
        $available = array_values(array_filter(
            $surfaces,
            static fn (ContractSurface $surface): bool => $surface->status === SemanticStatus::Ok,
        ));
        if (count($available) < 2) {
            return null;
        }

        /** @var array<string,array<string,true>> $sourcesByName */
        $sourcesByName = [];
        foreach ($available as $surface) {
            foreach ($surface->names as $name) {
                $sourcesByName[$name][$surface->source] = true;
            }
        }
        ksort($sourcesByName, SORT_STRING);

        $compared = array_map(static fn (ContractSurface $surface): string => $surface->source, $available);
        $common = [];
        $exclusive = ['resource' => [], 'schema' => [], 'alps' => []];
        $presence = [];
        foreach ($sourcesByName as $name => $sourceSet) {
            // PHP coerces numeric string array keys (for example "200") to integers.
            // Contract names are always strings on the Semantic API boundary.
            $name = (string) $name;
            $sources = [];
            foreach ($compared as $source) {
                if (isset($sourceSet[$source])) {
                    $sources[] = $source;
                }
            }
            if (count($sources) === count($compared)) {
                $common[] = $name;
            }
            if (count($sources) === 1) {
                $exclusive[$sources[0]][] = $name;
            }
            $presence[] = new ContractNamePresence($name, $sources);
        }

        return new ContractNameComparison(
            $compared,
            $common,
            $exclusive['resource'],
            $exclusive['schema'],
            $exclusive['alps'],
            $presence,
        );
    }

    private function relativeResourcePath(WorkspaceContext $workspace, ResourceFacts $facts): ?string
    {
        return $workspace->accessPolicy()->inspectExisting($facts->resource->file)->value?->relative;
    }

    private function localAlpsId(?string $reference): ?string
    {
        if ($reference === null || !str_starts_with($reference, '#')) {
            return null;
        }

        $id = rawurldecode(substr($reference, 1));

        return $id === '' ? null : $id;
    }

    /** @param list<string> $names @return list<string> */
    private function sortedNames(array $names): array
    {
        $unique = [];
        foreach ($names as $name) {
            // Keep the original string as the value because a numeric string key is
            // converted to an integer by PHP arrays.
            $unique[$name] = $name;
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }
}
