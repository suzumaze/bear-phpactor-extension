<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Sql;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Util\PathGuard;

/**
 * Resolves Ray.MediaQuery / Ray.QueryModule identifiers by BEAR convention.
 */
final class SqlQuery
{
    private const SQL_DIR = 'var/db/sql';

    /**
     * @return SemanticResult<SqlResolution|null>
     */
    public function resolve(Project $project, string $queryId): SemanticResult
    {
        if (!$this->isValidQueryId($queryId)) {
            return SemanticResult::invalidInput();
        }

        $projectRoot = realpath($project->root());
        $sqlRoot = realpath($project->root() . '/' . self::SQL_DIR);
        if ($projectRoot === false || $sqlRoot === false || !is_dir($sqlRoot)) {
            return SemanticResult::notFound();
        }
        $projectRoot = $this->normalize($projectRoot);
        $sqlRoot = $this->normalize($sqlRoot);
        if (!$this->contains($projectRoot, $sqlRoot)) {
            return SemanticResult::outsideWorkspace();
        }

        $candidate = realpath($sqlRoot . '/' . $queryId . '.sql');
        if ($candidate === false || !is_file($candidate)) {
            return SemanticResult::notFound();
        }
        $candidate = $this->normalize($candidate);
        if (!$this->contains($projectRoot, $candidate)) {
            return SemanticResult::outsideWorkspace();
        }
        if (!$this->contains($sqlRoot, $candidate)) {
            return SemanticResult::notFound();
        }

        return SemanticResult::ok(new SqlResolution($queryId, $candidate));
    }

    /**
     * Headless entry point with an explicit workspace boundary.
     *
     * @return SemanticResult<SqlResolution|null>
     */
    public function resolveInWorkspace(
        WorkspaceContext $workspace,
        string $queryId,
        ?string $contextPath = null,
    ): SemanticResult {
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        $result = $this->resolve($project->value, $queryId);
        if ($result->status !== SemanticStatus::Ok || $result->value === null) {
            return $result;
        }

        $path = $workspace->accessPolicy()->inspectExisting($result->value->file);
        if ($path->value === null) {
            return SemanticResult::failure($path->status);
        }

        return SemanticResult::ok(
            new SqlResolution($queryId, $path->value->absolute),
            [Provenance::savedFile($path->value->relative)],
        );
    }

    private function isValidQueryId(string $queryId): bool
    {
        if (
            $queryId === ''
            || str_contains($queryId, "\0")
            || str_contains($queryId, '\\')
            || PathGuard::isAbsolutePath($queryId)
        ) {
            return false;
        }

        foreach (explode('/', $queryId) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function contains(string $root, string $path): bool
    {
        if ($root === '/') {
            return str_starts_with($path, '/');
        }

        return $path === $root || str_starts_with($path, $root . '/');
    }

    private function normalize(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        return $normalized === '' ? '/' : $normalized;
    }
}
