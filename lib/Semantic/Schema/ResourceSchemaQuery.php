<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Schema;

use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFacts;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/** Shared source selection; configured runtime directories and weaving are not evaluated. */
final readonly class ResourceSchemaQuery
{
    public function __construct(
        private SchemaQuery $schemas = new SchemaQuery(),
        private ResourceFactsQuery $resources = new ResourceFactsQuery(),
    ) {
    }

    /** @return SemanticResult<SchemaResolution|null> */
    public function resolveInWorkspace(
        WorkspaceContext $workspace,
        string $uri,
        string $kind = SchemaQuery::KIND_RESPONSE,
        ?string $contextPath = null,
        ?string $method = null,
    ): SemanticResult {
        if (!in_array($kind, ['request', 'response'], true)) {
            return SemanticResult::unsupported();
        }
        if ($method !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,254}$/', $method) !== 1) {
            return SemanticResult::invalidInput();
        }
        $facts = $this->resources->describeInWorkspace($workspace, $uri, $contextPath);
        if ($facts->value === null && $facts->status !== SemanticStatus::Ambiguous) {
            return SemanticResult::failure($facts->status);
        }
        $candidates = [];
        $provenance = [];
        $failure = SemanticResult::notFound();
        foreach ($facts->value === null ? $facts->candidates : [$facts->value] as $resource) {
            $attributes = array_filter($resource->attributes, static fn ($attribute): bool =>
                $attribute->fqn === 'BEAR\\Resource\\Annotation\\JsonSchema' && $attribute->target === 'method');
            if ($method === null && $attributes === []) {
                $result = $this->schemas->resolveForResolutionInWorkspace($workspace, $resource->resource, $kind);
                if ($result->status === SemanticStatus::OutsideWorkspace) {
                    return $result;
                }
                if ($result->value !== null) {
                    $schema = $result->value;
                    $candidates[] = new SchemaResolution(
                        $kind,
                        $schema->source,
                        $schema->file,
                        $schema->titleOffset,
                        $resource->resource,
                        selection: 'convention_only'
                    );
                    array_push($provenance, ...$result->provenance);
                } elseif (
                    $facts->status === SemanticStatus::Ambiguous && $result->status === SemanticStatus::NotFound
                ) {
                    $candidates[] = new SchemaResolution(
                        $kind,
                        'convention',
                        null,
                        null,
                        $resource->resource,
                        selection: 'convention_only'
                    );
                } else {
                    $failure = $result;
                }
                continue;
            }
            foreach ($resource->methods as $resourceMethod) {
                if ($method !== null && $resourceMethod->name !== $method) {
                    continue;
                }
                $selection = $this->select($workspace, $resource, $resourceMethod->name, $kind);
                $result = $selection['result'];
                if (in_array($result->status, [SemanticStatus::OutsideWorkspace, SemanticStatus::InvalidInput], true)) {
                    return $result;
                }
                if ($method !== null && $facts->value !== null) {
                    return $result;
                }
                if ($result->value !== null) {
                    $candidates[] = $result->value;
                    array_push($provenance, ...$result->provenance);
                } elseif ($selection['selection'] !== 'not_declared' && $selection['selection'] !== 'convention_only') {
                    // Do not silently choose another method when an explicit selector is unresolved.
                    $failure = $result;
                    $candidates[] = new SchemaResolution(
                        $kind,
                        'attribute',
                        null,
                        null,
                        $resource->resource,
                        $resourceMethod->name,
                        $selection['selection']
                    );
                } elseif ($candidates === []) {
                    $failure = $result;
                }
            }
        }
        if (count($candidates) > 1) {
            return SemanticResult::ambiguous($candidates, provenance: $provenance);
        }
        if (count($candidates) === 1 && $candidates[0]->file !== null) {
            return SemanticResult::ok($candidates[0], $provenance);
        }

        return $failure;
    }

    /** @return array{result:SemanticResult<SchemaResolution|null>,subject:string,selection:string} */
    public function select(WorkspaceContext $workspace, ResourceFacts $facts, string $method, string $kind): array
    {
        $attributes = array_values(array_filter($facts->attributes, static fn ($attribute): bool =>
            $attribute->fqn === 'BEAR\\Resource\\Annotation\\JsonSchema'
            && $attribute->target === 'method' && $attribute->methodName === $method));
        $name = null;
        $dynamic = false;
        foreach ($attributes as $attribute) {
            foreach ($attribute->arguments as $index => $argument) {
                $matches = $kind === 'request'
                    ? $argument->name === 'params' || ($argument->name === null && $index === 2)
                    : $argument->name === 'schema' || ($argument->name === null && $index === 0);
                if (!$matches) {
                    continue;
                }
                if ($argument->valueType !== 'string' || !is_string($argument->value)) {
                    $dynamic = true;
                } else {
                    $name = $argument->value;
                }
            }
        }
        $selection = $attributes === [] ? 'convention_only' : 'bc_fallback';
        $subject = 'response:convention';
        if ($dynamic || count($attributes) > 1) {
            return ['result' => SemanticResult::unsupported(), 'subject' => $kind . ':dynamic',
                'selection' => 'dynamic'];
        }
        if ($name !== null && $name !== '' && $name !== '0') {
            $path = $workspace->accessPolicy()->inspectExisting($facts->resource->file);
            $result = $path->value === null ? SemanticResult::failure($path->status)
                : $this->schemas->resolveNamedInWorkspace($workspace, $name, $kind, $path->value->relative);
            $selection = 'attribute';
            $subject = $kind . ':' . $name;
        } elseif ($kind === 'request') {
            return ['result' => SemanticResult::unsupported(), 'subject' => 'request', 'selection' => 'not_declared'];
        } elseif ($attributes !== []) {
            $result = $this->schemas->resolveAdjacentInWorkspace($workspace, $facts->resource);
            $subject = 'response:bc_fallback';
        } else {
            $result = $this->schemas->resolveForResolutionInWorkspace($workspace, $facts->resource);
        }
        if ($result->value !== null) {
            $schema = $result->value;
            $result = SemanticResult::ok(new SchemaResolution(
                $kind,
                $schema->source,
                $schema->file,
                $schema->titleOffset,
                $facts->resource,
                $method,
                $selection,
            ), $result->provenance);
        }

        return ['result' => $result, 'subject' => $subject, 'selection' => $selection];
    }
}
