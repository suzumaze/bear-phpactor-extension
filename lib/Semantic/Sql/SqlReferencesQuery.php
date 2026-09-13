<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Sql;

use FilesystemIterator;
use Phpactor\TextDocument\TextDocumentBuilder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Sql\SqlQueryAtOffset;
use Suzumaze\BearPhpactor\Util\PathGuard;
use Throwable;
use UnexpectedValueException;

/**
 * Finds static Ray.MediaQuery / Ray.QueryModule references to an existing SQL file.
 *
 * Scanning is limited to canonical PSR-4 source roots inside the fixed workspace.
 * A query ID is only considered an identity after SqlQuery resolves its SQL file;
 * equal strings that point to no SQL file are not treated as references.
 */
final class SqlReferencesQuery
{
    private const MAX_PHP_BYTES = 1_048_576;

    public function __construct(
        private SqlQuery $sqlQuery = new SqlQuery(),
        private SqlQueryAtOffset $sqlQueryAtOffset = new SqlQueryAtOffset(),
    ) {
    }

    /** @return SemanticResult<SqlReferences|null> */
    public function findInWorkspace(
        WorkspaceContext $workspace,
        string $queryId,
        ?string $contextPath = null,
    ): SemanticResult {
        $sql = $this->sqlQuery->resolveInWorkspace($workspace, $queryId, $contextPath);
        if ($sql->status !== SemanticStatus::Ok || $sql->value === null) {
            return SemanticResult::failure($sql->status);
        }

        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        $references = [];
        $seenFiles = [];
        foreach ($project->value->psr4() as $directories) {
            foreach ($directories as $directory) {
                $base = PathGuard::isAbsolutePath($directory)
                    ? $directory
                    : $project->value->root() . '/' . $directory;
                $basePath = $workspace->accessPolicy()->inspectExisting($base);
                if ($basePath->value === null || !is_dir($basePath->value->absolute)) {
                    continue;
                }

                foreach ($this->phpFiles($basePath->value->absolute) as $candidate) {
                    $path = $workspace->accessPolicy()->inspectExisting($candidate);
                    if ($path->value === null || isset($seenFiles[$path->value->absolute])) {
                        continue;
                    }
                    $seenFiles[$path->value->absolute] = true;

                    $source = @file_get_contents(
                        $path->value->absolute,
                        false,
                        null,
                        0,
                        self::MAX_PHP_BYTES + 1,
                    );
                    if (
                        $source === false
                        || strlen($source) > self::MAX_PHP_BYTES
                        || (!str_contains($source, 'DbQuery') && !str_contains($source, '@Query'))
                    ) {
                        continue;
                    }

                    $document = TextDocumentBuilder::create($source)
                        ->uri($path->value->absolute)
                        ->language('php')
                        ->build();
                    try {
                        $found = $this->sqlQueryAtOffset->references($document);
                    } catch (Throwable) {
                        // Tolerant parsing should not throw. Treat malformed/unreadable
                        // source as an empty result if an implementation does fail.
                        continue;
                    }

                    foreach ($found as [$start, $foundId, $end]) {
                        if ($foundId !== $queryId) {
                            continue;
                        }
                        $references[] = new SqlReference(
                            $foundId,
                            $path->value->absolute,
                            $start,
                            $end,
                        );
                    }
                }
            }
        }

        usort(
            $references,
            static fn (SqlReference $left, SqlReference $right): int => [
                $left->file,
                $left->contentStart,
                $left->contentEnd,
                $left->queryId,
            ] <=> [
                $right->file,
                $right->contentStart,
                $right->contentEnd,
                $right->queryId,
            ],
        );

        return SemanticResult::ok(new SqlReferences($sql->value, $references));
    }

    /** @return iterable<string> */
    private function phpFiles(string $directory): iterable
    {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
        } catch (UnexpectedValueException) {
            return;
        }

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            yield $file->getPathname();
        }
    }
}
