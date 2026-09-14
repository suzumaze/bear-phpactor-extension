<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Sql;

use Phpactor\TextDocument\TextDocumentBuilder;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\Psr4PhpSourceScanner;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Sql\SqlQueryAtOffset;
use Throwable;

/**
 * Finds static Ray.MediaQuery / Ray.QueryModule references to an existing SQL file.
 *
 * Scanning is limited to canonical PSR-4 source roots inside the fixed workspace.
 * A query ID is only considered an identity after SqlQuery resolves its SQL file;
 * equal strings that point to no SQL file are not treated as references.
 */
final class SqlReferencesQuery
{
    public function __construct(
        private SqlQuery $sqlQuery = new SqlQuery(),
        private SqlQueryAtOffset $sqlQueryAtOffset = new SqlQueryAtOffset(),
        private Psr4PhpSourceScanner $sourceScanner = new Psr4PhpSourceScanner(),
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
        foreach ($this->sourceScanner->scan($workspace, $project->value, ['DbQuery', '@Query']) as $source) {
            $document = TextDocumentBuilder::create($source->contents)
                ->uri($source->file)
                ->language('php')
                ->build();
            try {
                $found = $this->sqlQueryAtOffset->references($document);
            } catch (Throwable) {
                // Tolerant parsing should not throw. Treat malformed source as empty.
                continue;
            }

            foreach ($found as [$start, $foundId, $end]) {
                if ($foundId !== $queryId) {
                    continue;
                }
                $references[] = new SqlReference(
                    $foundId,
                    $source->file,
                    $start,
                    $end,
                );
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
}
