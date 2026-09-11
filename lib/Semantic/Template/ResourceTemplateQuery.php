<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Template;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceResolution;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
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
     * @param SemanticResult<ResourceResolution|null> $resource
     * @return SemanticResult<ResourceTemplateResolution|null>
     */
    private function fromResourceResult(Project $project, string $engine, SemanticResult $resource): SemanticResult
    {
        if ($resource->status === SemanticStatus::Ok && $resource->value !== null) {
            $resolution = $this->templateFor($project, $resource->value, $engine);
            if ($resolution->status !== SemanticStatus::Ok || $resolution->value === null) {
                return SemanticResult::failure($resolution->status);
            }

            return $resolution;
        }
        if ($resource->status !== SemanticStatus::Ambiguous) {
            return SemanticResult::failure($resource->status);
        }

        $candidates = [];
        foreach ($resource->candidates as $candidate) {
            $template = $this->templatePath($project, $candidate, $engine);
            if ($template->status === SemanticStatus::OutsideWorkspace) {
                return SemanticResult::outsideWorkspace();
            }
            $candidates[] = new ResourceTemplateResolution($candidate, $engine, $template->value);
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
        $template = $this->templatePath($project, $resource, $engine);
        if ($template->value === null) {
            return SemanticResult::failure($template->status);
        }

        return SemanticResult::ok(new ResourceTemplateResolution($resource, $engine, $template->value));
    }

    /** @return SemanticResult<string|null> */
    private function templatePath(
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

        foreach (array_values(array_unique($paths)) as $path) {
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

            return SemanticResult::ok($this->normalize($canonical));
        }

        return SemanticResult::notFound();
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
        if ($result->status === SemanticStatus::Ok && $result->value !== null) {
            $checked = $this->workspaceResolution($workspace, $result->value);

            return $checked->value === null ? SemanticResult::failure($checked->status) : $checked;
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
        if ($resolution->templateFile === null) {
            return SemanticResult::ok($resolution);
        }

        $path = $workspace->accessPolicy()->inspectExisting($resolution->templateFile);
        if ($path->value === null) {
            return SemanticResult::failure($path->status);
        }

        return SemanticResult::ok(new ResourceTemplateResolution(
            $resolution->resource,
            $resolution->engine,
            $path->value->absolute,
        ));
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
