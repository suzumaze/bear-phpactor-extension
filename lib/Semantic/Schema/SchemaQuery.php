<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Schema;

use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaPathResolver;
use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceResolution;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Util\PathGuard;

/**
 * Resolves BEAR request/response JSON Schemas without LSP positions.
 */
final readonly class SchemaQuery
{
    public const KIND_REQUEST = 'request';
    public const KIND_RESPONSE = 'response';
    public const SOURCE_ATTRIBUTE = 'attribute';
    public const SOURCE_CONVENTION = 'convention';

    public function __construct(
        private JsonSchemaPathResolver $pathResolver = new JsonSchemaPathResolver(),
        private ResourceQuery $resourceQuery = new ResourceQuery(),
    ) {
    }

    /**
     * Resolve a file name explicitly declared in #[JsonSchema].
     *
     * @return SemanticResult<SchemaResolution|null>
     */
    public function resolveNamed(Project $project, string $fileName, string $kind): SemanticResult
    {
        if (!$this->supportsKind($kind)) {
            return SemanticResult::unsupported();
        }
        if (!$this->isValidFileName($fileName)) {
            return SemanticResult::invalidInput();
        }

        $directory = $kind === self::KIND_REQUEST
            ? JsonSchemaPathResolver::REQUEST_SCHEMA_DIR
            : JsonSchemaPathResolver::RESPONSE_SCHEMA_DIR;

        return $this->resolutionForPath(
            $project,
            $project->root() . '/' . $directory . '/' . $fileName,
            $kind,
            self::SOURCE_ATTRIBUTE,
            allowedRoot: $project->root() . '/' . $directory,
        );
    }

    /**
     * Resolve the response schema selected by the Resource class convention.
     *
     * @return SemanticResult<SchemaResolution|null>
     */
    public function resolveForResource(
        Project $project,
        string $resourceUri,
        string $kind = self::KIND_RESPONSE,
    ): SemanticResult {
        if (!$this->supportsKind($kind)) {
            return SemanticResult::unsupported();
        }
        if ($kind === self::KIND_REQUEST) {
            return SemanticResult::unsupported();
        }

        $resource = $this->resourceQuery->resolveString($project, $resourceUri);
        if ($resource->status === SemanticStatus::Ok && $resource->value !== null) {
            return $this->conventionForResource($project, $resource->value);
        }
        if ($resource->status !== SemanticStatus::Ambiguous) {
            return SemanticResult::failure($resource->status);
        }

        $candidates = [];
        foreach ($resource->candidates as $candidate) {
            $schema = $this->conventionForResource($project, $candidate);
            if ($schema->status === SemanticStatus::OutsideWorkspace) {
                return SemanticResult::outsideWorkspace();
            }
            $candidates[] = $schema->value ?? new SchemaResolution(
                self::KIND_RESPONSE,
                self::SOURCE_CONVENTION,
                null,
                null,
                $candidate,
            );
        }
        usort(
            $candidates,
            static fn (SchemaResolution $left, SchemaResolution $right): int =>
                [$left->resource->file, $left->file ?? '']
                <=> [$right->resource->file, $right->file ?? ''],
        );

        return SemanticResult::ambiguous($candidates);
    }

    /** @return SemanticResult<SchemaResolution|null> */
    public function resolveNamedInWorkspace(
        WorkspaceContext $workspace,
        string $fileName,
        string $kind,
        ?string $contextPath = null,
    ): SemanticResult {
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        return $this->enforceWorkspace(
            $workspace,
            $this->resolveNamed($project->value, $fileName, $kind),
        );
    }

    /** @return SemanticResult<SchemaResolution|null> */
    public function resolveForResourceInWorkspace(
        WorkspaceContext $workspace,
        string $resourceUri,
        string $kind = self::KIND_RESPONSE,
        ?string $contextPath = null,
    ): SemanticResult {
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        return $this->enforceWorkspace(
            $workspace,
            $this->resolveForResource($project->value, $resourceUri, $kind),
        );
    }

    /** @return SemanticResult<SchemaResolution|null> */
    private function conventionForResource(Project $project, ResourceResolution $resource): SemanticResult
    {
        $separator = strrpos($resource->fqn, '\\');
        if ($separator === false) {
            return SemanticResult::unsupported();
        }
        $namespace = substr($resource->fqn, 0, $separator);
        $className = substr($resource->fqn, $separator + 1);
        $path = $this->pathResolver->conventionPath(
            $project->root(),
            $project->psr4(),
            $resource->file,
            $namespace,
            $className,
        );
        if ($path === null) {
            return SemanticResult::notFound();
        }

        return $this->resolutionForPath(
            $project,
            $path,
            self::KIND_RESPONSE,
            self::SOURCE_CONVENTION,
            $resource,
            $this->schemaDirectory($path),
        );
    }

    /** @return SemanticResult<SchemaResolution|null> */
    private function resolutionForPath(
        Project $project,
        string $path,
        string $kind,
        string $source,
        ?ResourceResolution $resource = null,
        ?string $allowedRoot = null,
    ): SemanticResult {
        $canonicalAllowedRoot = $allowedRoot === null ? null : realpath($allowedRoot);
        if ($allowedRoot !== null && ($canonicalAllowedRoot === false || !is_dir($canonicalAllowedRoot))) {
            return SemanticResult::notFound();
        }
        $canonical = realpath($path);
        if ($canonical === false || !is_file($canonical)) {
            return SemanticResult::notFound();
        }
        if (!$this->isInside($project->root(), $canonical)) {
            return SemanticResult::outsideWorkspace();
        }
        if (is_string($canonicalAllowedRoot) && !$this->isInside($canonicalAllowedRoot, $canonical)) {
            return SemanticResult::outsideWorkspace();
        }
        $canonical = $this->normalize($canonical);

        return SemanticResult::ok(new SchemaResolution(
            $kind,
            $source,
            $canonical,
            $this->pathResolver->titleKeyOffset($canonical),
            $resource,
        ));
    }

    private function supportsKind(string $kind): bool
    {
        return in_array($kind, [self::KIND_REQUEST, self::KIND_RESPONSE], true);
    }

    private function schemaDirectory(string $path): ?string
    {
        $normalized = $this->normalize($path);
        $marker = '/' . JsonSchemaPathResolver::RESPONSE_SCHEMA_DIR . '/';
        $position = strrpos($normalized, $marker);
        if ($position === false) {
            return null;
        }

        return substr($normalized, 0, $position + strlen($marker) - 1);
    }

    private function isValidFileName(string $fileName): bool
    {
        if (
            $fileName === ''
            || !str_contains($fileName, '.json')
            || str_contains($fileName, "\0")
            || str_contains($fileName, '\\')
            || PathGuard::isAbsolutePath($fileName)
        ) {
            return false;
        }
        foreach (explode('/', $fileName) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param SemanticResult<SchemaResolution|null> $result
     * @return SemanticResult<SchemaResolution|null>
     */
    private function enforceWorkspace(WorkspaceContext $workspace, SemanticResult $result): SemanticResult
    {
        if ($result->status === SemanticStatus::Ok && $result->value !== null) {
            return $this->workspaceResolution($workspace, $result->value);
        }
        if ($result->status !== SemanticStatus::Ambiguous) {
            return $result;
        }

        $candidates = [];
        foreach ($result->candidates as $candidate) {
            $checked = $this->workspaceResolution($workspace, $candidate);
            if ($checked->value === null) {
                return SemanticResult::failure($checked->status);
            }
            $candidates[] = $checked->value;
        }

        return SemanticResult::ambiguous($candidates);
    }

    /** @return SemanticResult<SchemaResolution|null> */
    private function workspaceResolution(
        WorkspaceContext $workspace,
        SchemaResolution $resolution,
    ): SemanticResult {
        if ($resolution->file === null) {
            return SemanticResult::ok($resolution);
        }
        $path = $workspace->accessPolicy()->inspectExisting($resolution->file);
        if ($path->value === null) {
            return SemanticResult::failure($path->status);
        }

        return SemanticResult::ok(new SchemaResolution(
            $resolution->kind,
            $resolution->source,
            $path->value->absolute,
            $resolution->titleOffset,
            $resolution->resource,
        ));
    }

    private function isInside(string $root, string $path): bool
    {
        $canonicalRoot = realpath($root);
        if ($canonicalRoot === false) {
            return false;
        }
        $canonicalRoot = $this->normalize($canonicalRoot);
        $canonicalPath = $this->normalize($path);

        return $canonicalRoot === '/'
            ? str_starts_with($canonicalPath, '/')
            : $canonicalPath === $canonicalRoot || str_starts_with($canonicalPath, $canonicalRoot . '/');
    }

    private function normalize(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        return $normalized === '' ? '/' : $normalized;
    }
}
