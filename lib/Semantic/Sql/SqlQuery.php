<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Sql;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use UnexpectedValueException;
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
    public const CONVENTION_DIRECTORY = 'var/db/sql';

    public function __construct(
        private readonly int $maxScanEntries = 5000,
        private readonly int $maxCandidates = 100,
    ) {
        if ($maxScanEntries < 1 || $maxCandidates < 1) {
            throw new \InvalidArgumentException('SQL search limits must be positive.');
        }
    }

    /**
     * @return SemanticResult<SqlResolution|null>
     */
    public function resolve(Project $project, string $queryId): SemanticResult
    {
        if (!$this->isValidQueryId($queryId)) {
            return SemanticResult::invalidInput();
        }

        $projectRoot = realpath($project->root());
        $sqlRoot = realpath($project->root() . '/' . self::CONVENTION_DIRECTORY);
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
            return $this->suggestions($sqlRoot, $queryId);
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
        if ($result->status === SemanticStatus::NotFound && $result->candidates !== []) {
            $candidates = [];
            $provenance = [];
            foreach ($result->candidates as $candidate) {
                $path = $workspace->accessPolicy()->inspectExisting($candidate->file);
                if ($path->value !== null) {
                    $candidates[] = new SqlResolution($candidate->queryId, $path->value->absolute);
                    $provenance[] = Provenance::savedFile($path->value->relative);
                }
            }

            return SemanticResult::notFoundWithCandidates($candidates, $provenance)->withCoverage($result->coverage);
        }
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

    /** @return SemanticResult<SqlResolution|null> */
    private function suggestions(string $sqlRoot, string $queryId): SemanticResult
    {
        $candidates = [];
        $scanned = 0;
        $truncated = false;
        $readFailed = false;
        try {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sqlRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($files as $file) {
                if ($scanned >= $this->maxScanEntries) {
                    $truncated = true;
                    break;
                }
                ++$scanned;
                if (!$file->isFile() || $file->getFilename() !== basename($queryId) . '.sql') {
                    continue;
                }
                $canonical = realpath($file->getPathname());
                if ($canonical === false || !$this->contains($sqlRoot, $this->normalize($canonical))) {
                    continue;
                }
                $id = substr($this->normalize($file->getPathname()), strlen($sqlRoot) + 1, -4);
                $candidates[$id] = new SqlResolution($id, $this->normalize($canonical));
            }
        } catch (UnexpectedValueException) {
            $readFailed = true;
        }
        ksort($candidates, SORT_STRING);

        return SemanticResult::notFoundWithCandidates(
            array_slice(array_values($candidates), 0, $this->maxCandidates),
        )->withCoverage(['suggestionScan' => [
            'scannedEntries' => $scanned,
            'scanLimit' => $this->maxScanEntries,
            'scanTruncated' => $truncated,
            'readFailed' => $readFailed,
            'followsDirectorySymlinks' => false,
            'candidateCount' => count($candidates),
            'candidateLimit' => $this->maxCandidates,
            'candidatesTruncated' => count($candidates) > $this->maxCandidates,
            'complete' => !$truncated && !$readFailed && count($candidates) <= $this->maxCandidates,
        ]]);
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
