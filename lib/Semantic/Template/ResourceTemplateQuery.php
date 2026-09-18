<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Template;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceResolution;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Template\TemplateReference;

/**
 * Resolves a Resource to its Twig or Qiq template by BEAR's standard layout.
 */
final readonly class ResourceTemplateQuery
{
    private const TWIG_EXTENSION = '.html.twig';
    private const QIQ_ROOT = 'var/qiq/template';
    private const QIQ_EXTENSION = '.php';
    private const TWIG_ROOT = 'var/templates';

    public function __construct(
        private ResourceQuery $resourceQuery = new ResourceQuery(),
    ) {
    }

    /**
     * @return SemanticResult<ResourceTemplateResolution|null>
     */
    public function resolve(Project $project, string $resourceUri, string $engine): SemanticResult
    {
        if (!$this->supports($engine)) {
            return SemanticResult::unsupported();
        }

        $resource = $this->resourceQuery->resolveString($project, $resourceUri);

        return $this->fromResourceResult($project, $engine, $resource);
    }

    /**
     * Headless entry point with an explicit workspace boundary.
     *
     * @return SemanticResult<ResourceTemplateResolution|null>
     */
    public function resolveInWorkspace(
        WorkspaceContext $workspace,
        string $resourceUri,
        string $engine,
        ?string $contextPath = null,
    ): SemanticResult {
        if (!$this->supports($engine)) {
            return SemanticResult::unsupported();
        }

        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }
        $resource = $this->resourceQuery->resolveInWorkspace($workspace, $resourceUri, $contextPath);
        $result = $this->fromResourceResult($project->value, $engine, $resource);

        return $this->enforceWorkspace($workspace, $result);
    }

    /**
     * Resolve a template for a Resource already selected by another semantic
     * query. The physical Resource path remains the identity when its URI is
     * otherwise ambiguous.
     *
     * @return SemanticResult<ResourceTemplateResolution|null>
     */
    public function resolveForResolutionInWorkspace(
        WorkspaceContext $workspace,
        ResourceResolution $resource,
        string $engine,
    ): SemanticResult {
        if (!$this->supports($engine)) {
            return SemanticResult::unsupported();
        }

        $path = $workspace->accessPolicy()->inspectExisting($resource->file);
        if ($path->value === null) {
            return SemanticResult::failure($path->status);
        }
        $project = $workspace->project($path->value->relative);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        return $this->enforceWorkspace(
            $workspace,
            $this->templateFor(
                $project->value,
                new ResourceResolution($resource->uri, $path->value->absolute, $resource->fqn),
                $engine,
            ),
        );
    }

    /**
     * @param SemanticResult<ResourceResolution|null> $resource
     * @return SemanticResult<ResourceTemplateResolution|null>
     */
    private function fromResourceResult(Project $project, string $engine, SemanticResult $resource): SemanticResult
    {
        if ($resource->status === SemanticStatus::Ok && $resource->value !== null) {
            $resolution = $this->templateFor($project, $resource->value, $engine);
            if ($resolution->value === null && $resolution->partial === null) {
                return SemanticResult::failure($resolution->status);
            }

            return $resolution;
        }
        if ($resource->status !== SemanticStatus::Ambiguous) {
            return SemanticResult::failure($resource->status);
        }

        $candidates = [];
        foreach ($resource->candidates as $candidate) {
            $template = $this->templateFor($project, $candidate, $engine);
            if ($template->status === SemanticStatus::OutsideWorkspace) {
                return SemanticResult::outsideWorkspace();
            }
            $candidates[] = $template->value
                ?? $template->partial
                ?? new ResourceTemplateResolution($candidate, $engine, null);
        }
        usort(
            $candidates,
            static fn (ResourceTemplateResolution $left, ResourceTemplateResolution $right): int =>
                [$left->resource->file, $left->templateFile ?? '']
                <=> [$right->resource->file, $right->templateFile ?? ''],
        );

        return SemanticResult::ambiguous($candidates);
    }

    /** @return SemanticResult<ResourceTemplateResolution|null> */
    private function templateFor(
        Project $project,
        ResourceResolution $resource,
        string $engine,
    ): SemanticResult {
        $paths = $this->templatePaths($project, $resource, $engine);
        if ($paths->value === null) {
            return SemanticResult::failure($paths->status);
        }

        $searched = [];
        foreach ($paths->value as $path) {
            $searched[] = $path;
            if (!is_file($path)) {
                continue;
            }
            $canonical = realpath($path);
            if ($canonical === false) {
                continue;
            }
            if (!$this->isInside($project->root(), $canonical)) {
                return SemanticResult::outsideWorkspace();
            }

            return SemanticResult::ok(new ResourceTemplateResolution(
                $resource,
                $engine,
                $this->normalize($canonical),
                $searched,
            ));
        }

        return SemanticResult::notFoundWithPartial(
            new ResourceTemplateResolution($resource, $engine, null, $searched),
        );
    }

    /** @return SemanticResult<list<string>|null> */
    private function templatePaths(
        Project $project,
        ResourceResolution $resource,
        string $engine,
    ): SemanticResult {
        $relative = $this->relativeResourcePath($resource->file);
        if ($relative === null) {
            return SemanticResult::unsupported();
        }

        $paths = $engine === TemplateReference::ENGINE_TWIG
            ? [
                substr($resource->file, 0, -4) . self::TWIG_EXTENSION,
                $project->root() . '/' . self::TWIG_ROOT . '/'
                    . substr($relative, 0, -4) . self::TWIG_EXTENSION,
            ]
            : [
                $project->root() . '/' . self::QIQ_ROOT . '/'
                    . substr($relative, 0, -4) . self::QIQ_EXTENSION,
            ];

        return SemanticResult::ok(array_values(array_unique($paths)));
    }

    private function relativeResourcePath(string $resourceFile): ?string
    {
        $marker = '/Resource/';
        $position = strrpos($this->normalize($resourceFile), $marker);
        if ($position === false) {
            return null;
        }

        $relative = substr($resourceFile, $position + strlen($marker));

        return str_ends_with($relative, '.php') && $relative !== '.php' ? $relative : null;
    }

    /**
     * @param SemanticResult<ResourceTemplateResolution|null> $result
     * @return SemanticResult<ResourceTemplateResolution|null>
     */
    private function enforceWorkspace(WorkspaceContext $workspace, SemanticResult $result): SemanticResult
    {
        if ($result->value !== null) {
            $checked = $this->workspaceResolution($workspace, $result->value);
            if ($checked->value === null) {
                return SemanticResult::failure($checked->status);
            }

            return $checked;
        }
        if ($result->partial !== null) {
            $checked = $this->workspaceResolution($workspace, $result->partial);
            if ($checked->value === null) {
                return SemanticResult::failure($checked->status);
            }

            return SemanticResult::notFoundWithPartial(
                $checked->value,
                $result->error,
                [...$result->provenance, ...$checked->provenance],
            );
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

    /** @return SemanticResult<ResourceTemplateResolution|null> */
    private function workspaceResolution(
        WorkspaceContext $workspace,
        ResourceTemplateResolution $resolution,
    ): SemanticResult {
        $resourcePath = $workspace->accessPolicy()->inspectExisting($resolution->resource->file);
        if ($resourcePath->value === null) {
            return SemanticResult::failure($resourcePath->status);
        }
        foreach ($resolution->searchedFiles as $searchedFile) {
            if (!$this->isInside($workspace->root(), $searchedFile)) {
                return SemanticResult::outsideWorkspace();
            }
        }

        $resource = new ResourceResolution(
            $resolution->resource->uri,
            $resourcePath->value->absolute,
            $resolution->resource->fqn,
        );
        $provenance = [
            Provenance::savedFile($resourcePath->value->relative),
            Provenance::derived(),
        ];
        if ($resolution->templateFile === null) {
            return SemanticResult::ok(
                new ResourceTemplateResolution(
                    $resource,
                    $resolution->engine,
                    null,
                    $resolution->searchedFiles,
                ),
                $provenance,
            );
        }

        $path = $workspace->accessPolicy()->inspectExisting($resolution->templateFile);
        if ($path->value === null) {
            return SemanticResult::failure($path->status);
        }

        return SemanticResult::ok(
            new ResourceTemplateResolution(
                $resource,
                $resolution->engine,
                $path->value->absolute,
                $resolution->searchedFiles,
            ),
            [
                ...$provenance,
                Provenance::savedFile($path->value->relative),
            ],
        );
    }

    private function supports(string $engine): bool
    {
        return in_array($engine, [TemplateReference::ENGINE_TWIG, TemplateReference::ENGINE_QIQ], true);
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
