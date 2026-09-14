<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Template;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Template\TemplatePathResolver;
use Suzumaze\BearPhpactor\Template\TemplateReference;

/**
 * Resolves static Twig/Qiq names without depending on editor positions.
 */
final readonly class TemplateQuery
{
    public function __construct(
        private TemplatePathResolver $pathResolver = new TemplatePathResolver(),
    ) {
    }

    /**
     * @return SemanticResult<TemplateResolution|null>
     */
    public function resolve(
        Project $project,
        string $engine,
        string $name,
        ?string $documentPath = null,
    ): SemanticResult {
        $validation = $this->validate($engine, $name, $documentPath);
        if ($validation !== null) {
            return SemanticResult::failure($validation);
        }

        $canonicalDocument = $documentPath;
        if ($documentPath !== null) {
            $canonicalDocument = realpath($documentPath);
            if ($canonicalDocument === false) {
                return SemanticResult::notFound();
            }
            if (!$this->isInside($project->root(), $canonicalDocument)) {
                return SemanticResult::outsideWorkspace();
            }
        }

        $reference = new TemplateReference($engine, $name, 0, strlen($name));
        $file = $this->pathResolver->resolve(
            $reference,
            $project->root(),
            $canonicalDocument ?? $project->root(),
        );
        if ($file === null) {
            return SemanticResult::notFound();
        }

        $canonicalFile = realpath($file);
        if ($canonicalFile === false) {
            return SemanticResult::notFound();
        }
        if (!$this->isInside($project->root(), $canonicalFile)) {
            return SemanticResult::outsideWorkspace();
        }

        return SemanticResult::ok(new TemplateResolution(
            $engine,
            $name,
            $this->normalize($canonicalFile),
        ));
    }

    /**
     * Headless entry point with workspace-relative context input.
     *
     * @return SemanticResult<TemplateResolution|null>
     */
    public function resolveInWorkspace(
        WorkspaceContext $workspace,
        string $engine,
        string $name,
        ?string $contextPath = null,
    ): SemanticResult {
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        $documentPath = null;
        if ($contextPath !== null) {
            $path = $workspace->accessPolicy()->resolveExisting($contextPath);
            if ($path->value === null) {
                return SemanticResult::failure($path->status);
            }
            $documentPath = $path->value->absolute;
        }

        $result = $this->resolve($project->value, $engine, $name, $documentPath);
        if ($result->status !== SemanticStatus::Ok || $result->value === null) {
            return $result;
        }

        $path = $workspace->accessPolicy()->inspectExisting($result->value->file);
        if ($path->value === null) {
            return SemanticResult::failure($path->status);
        }

        return SemanticResult::ok(
            new TemplateResolution(
                $result->value->engine,
                $result->value->name,
                $path->value->absolute,
            ),
            [Provenance::savedFile($path->value->relative)],
        );
    }

    private function validate(string $engine, string $name, ?string $documentPath): ?SemanticStatus
    {
        if (!in_array($engine, [TemplateReference::ENGINE_TWIG, TemplateReference::ENGINE_QIQ], true)) {
            return SemanticStatus::Unsupported;
        }
        if ($name === '' || str_contains($name, "\0")) {
            return SemanticStatus::InvalidInput;
        }
        if ($engine === TemplateReference::ENGINE_TWIG) {
            if (str_starts_with($name, '@')) {
                return SemanticStatus::Unsupported;
            }

            return $this->twigEscapesRoot($name) ? SemanticStatus::InvalidInput : null;
        }
        if (str_contains($name, ':')) {
            return SemanticStatus::Unsupported;
        }
        if (str_contains($name, '\\')) {
            return SemanticStatus::InvalidInput;
        }
        if (str_starts_with($name, '.') && $documentPath === null) {
            return SemanticStatus::InvalidInput;
        }

        return null;
    }

    private function twigEscapesRoot(string $name): bool
    {
        $depth = 0;
        foreach (explode('/', ltrim(str_replace('\\', '/', $name), '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($depth === 0) {
                    return true;
                }
                --$depth;

                continue;
            }
            ++$depth;
        }

        return false;
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
