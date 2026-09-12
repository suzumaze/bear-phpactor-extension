<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Schema;

use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use JsonException;
use stdClass;

/**
 * Extracts bounded, statically knowable facts from a resolved JSON Schema.
 * External references are not fetched or expanded.
 */
final readonly class SchemaFactsQuery
{
    private const MAX_JSON_BYTES = 1048576;
    private const MAX_JSON_DEPTH = 64;

    public function __construct(
        private SchemaQuery $schemaQuery = new SchemaQuery(),
    ) {
    }

    /** @return SemanticResult<SchemaFacts|null> */
    public function describeNamedInWorkspace(
        WorkspaceContext $workspace,
        string $fileName,
        string $kind,
        ?string $contextPath = null,
    ): SemanticResult {
        return $this->fromResolutionResult(
            $workspace,
            $this->schemaQuery->resolveNamedInWorkspace($workspace, $fileName, $kind, $contextPath),
        );
    }

    /** @return SemanticResult<SchemaFacts|null> */
    public function describeForResourceInWorkspace(
        WorkspaceContext $workspace,
        string $resourceUri,
        string $kind = SchemaQuery::KIND_RESPONSE,
        ?string $contextPath = null,
    ): SemanticResult {
        return $this->fromResolutionResult(
            $workspace,
            $this->schemaQuery->resolveForResourceInWorkspace(
                $workspace,
                $resourceUri,
                $kind,
                $contextPath,
            ),
        );
    }

    /**
     * @param SemanticResult<SchemaResolution|null> $result
     * @return SemanticResult<SchemaFacts|null>
     */
    private function fromResolutionResult(WorkspaceContext $workspace, SemanticResult $result): SemanticResult
    {
        if ($result->status === SemanticStatus::Ok && $result->value !== null) {
            return $this->facts($workspace, $result->value);
        }
        if ($result->status !== SemanticStatus::Ambiguous) {
            return SemanticResult::failure($result->status);
        }

        $candidates = [];
        foreach ($result->candidates as $candidate) {
            if ($candidate->file === null) {
                $candidates[] = SchemaFacts::unavailable($candidate);
                continue;
            }
            $facts = $this->facts($workspace, $candidate);
            if ($facts->value === null) {
                return SemanticResult::failure($facts->status);
            }
            $candidates[] = $facts->value;
        }

        return SemanticResult::ambiguous($candidates);
    }

    /** @return SemanticResult<SchemaFacts|null> */
    private function facts(WorkspaceContext $workspace, SchemaResolution $schema): SemanticResult
    {
        if ($schema->file === null) {
            return SemanticResult::ok(SchemaFacts::unavailable($schema));
        }

        $path = $workspace->accessPolicy()->inspectExisting($schema->file);
        if ($path->value === null) {
            return SemanticResult::failure($path->status);
        }
        $source = @file_get_contents($path->value->absolute, false, null, 0, self::MAX_JSON_BYTES + 1);
        if ($source === false) {
            return SemanticResult::notFound();
        }
        if (strlen($source) > self::MAX_JSON_BYTES) {
            return SemanticResult::parseError();
        }

        try {
            $decoded = json_decode($source, false, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return SemanticResult::parseError();
        }

        $types = $decoded instanceof stdClass ? $this->types($decoded->type ?? null) : [];
        $required = [];
        if ($decoded instanceof stdClass && is_array($decoded->required ?? null)) {
            foreach ($decoded->required as $name) {
                if (is_string($name)) {
                    $required[$name] = true;
                }
            }
        }

        $properties = [];
        $definitions = $decoded instanceof stdClass ? $decoded->properties ?? null : null;
        if ($definitions instanceof stdClass) {
            foreach (get_object_vars($definitions) as $name => $definition) {
                // PHP arrays normalize a numeric JSON object key to int;
                // restore the JSON property-name type before building the DTO.
                $name = (string) $name;
                $properties[] = new SchemaPropertyFact(
                    $name,
                    isset($required[$name]),
                    $definition instanceof stdClass ? $this->types($definition->type ?? null) : [],
                );
            }
        }
        usort(
            $properties,
            static fn (SchemaPropertyFact $left, SchemaPropertyFact $right): int => $left->name <=> $right->name,
        );

        return SemanticResult::ok(new SchemaFacts(
            new SchemaResolution(
                $schema->kind,
                $schema->source,
                $path->value->absolute,
                $schema->titleOffset,
                $schema->resource,
            ),
            true,
            $types,
            $properties,
        ));
    }

    /** @return list<string> */
    private function types(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $types = [];
        foreach ($value as $type) {
            if (is_string($type)) {
                $types[$type] = true;
            }
        }
        ksort($types, SORT_STRING);

        return array_keys($types);
    }
}
