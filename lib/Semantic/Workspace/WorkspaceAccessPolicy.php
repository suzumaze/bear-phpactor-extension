<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Workspace;

use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Util\PathGuard;

/**
 * Canonical workspace containment shared by headless semantic queries.
 */
final readonly class WorkspaceAccessPolicy
{
    private function __construct(
        private string $canonicalRoot,
    ) {
    }

    /**
     * @return SemanticResult<self|null>
     */
    public static function fromRoot(string $root): SemanticResult
    {
        if ($root === '' || str_contains($root, "\0") || !PathGuard::isAbsolutePath($root)) {
            return SemanticResult::invalidInput();
        }

        $canonicalRoot = realpath($root);
        if ($canonicalRoot === false || !is_dir($canonicalRoot)) {
            return SemanticResult::notFound();
        }

        return SemanticResult::ok(new self(self::normalize($canonicalRoot)));
    }

    public function root(): string
    {
        return $this->canonicalRoot;
    }

    /**
     * Resolve a caller-supplied workspace-relative path.
     *
     * @return SemanticResult<WorkspacePath|null>
     */
    public function resolveExisting(string $relativePath): SemanticResult
    {
        if (!$this->isSafeRelativePath($relativePath)) {
            return SemanticResult::invalidInput();
        }

        return $this->inspectExisting($this->canonicalRoot . '/' . $relativePath);
    }

    /**
     * Check an absolute path produced by an internal resolver.
     *
     * @return SemanticResult<WorkspacePath|null>
     */
    public function inspectExisting(string $absolutePath): SemanticResult
    {
        if (
            $absolutePath === ''
            || str_contains($absolutePath, "\0")
            || !PathGuard::isAbsolutePath($absolutePath)
        ) {
            return SemanticResult::invalidInput();
        }

        $canonical = realpath($absolutePath);
        if ($canonical === false) {
            return SemanticResult::notFound();
        }
        $canonical = self::normalize($canonical);

        if (!$this->containsCanonical($canonical)) {
            return SemanticResult::outsideWorkspace();
        }

        $relative = $canonical === $this->canonicalRoot
            ? ''
            : substr($canonical, strlen($this->canonicalRoot) + 1);

        return SemanticResult::ok(new WorkspacePath($canonical, $relative));
    }

    public function containsExisting(string $absolutePath): bool
    {
        return $this->inspectExisting($absolutePath)->value !== null;
    }

    private function isSafeRelativePath(string $path): bool
    {
        if (
            $path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || PathGuard::isAbsolutePath($path)
        ) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function containsCanonical(string $canonical): bool
    {
        if ($this->canonicalRoot === '/') {
            return str_starts_with($canonical, '/');
        }

        return $canonical === $this->canonicalRoot
            || str_starts_with($canonical, $this->canonicalRoot . '/');
    }

    private static function normalize(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        return $normalized === '' ? '/' : $normalized;
    }
}
