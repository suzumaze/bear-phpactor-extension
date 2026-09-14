<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Schema;

use Phpactor\TextDocument\TextDocumentBuilder;
use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaReferenceAtOffset;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\Psr4PhpSourceScanner;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Throwable;

/**
 * Finds explicit JsonSchema attributes that resolve to the same existing file.
 */
final class SchemaReferencesQuery
{
    public function __construct(
        private SchemaQuery $schemaQuery = new SchemaQuery(),
        private JsonSchemaReferenceAtOffset $referenceAtOffset = new JsonSchemaReferenceAtOffset(),
        private Psr4PhpSourceScanner $sourceScanner = new Psr4PhpSourceScanner(),
    ) {
    }

    /** @return SemanticResult<SchemaReferences|null> */
    public function findNamedInWorkspace(
        WorkspaceContext $workspace,
        string $fileName,
        string $kind,
        ?string $contextPath = null,
    ): SemanticResult {
        $target = $this->schemaQuery->resolveNamedInWorkspace($workspace, $fileName, $kind, $contextPath);
        if ($target->status !== SemanticStatus::Ok || $target->value === null) {
            return SemanticResult::failure($target->status);
        }
        if ($target->value->file === null) {
            return SemanticResult::notFound();
        }

        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        $references = [];
        foreach ($this->sourceScanner->scan($workspace, $project->value, ['JsonSchema']) as $source) {
            $document = TextDocumentBuilder::create($source->contents)
                ->uri($source->file)
                ->language('php')
                ->build();
            try {
                $found = $this->referenceAtOffset->references($document);
            } catch (Throwable) {
                continue;
            }

            $sourcePath = $workspace->accessPolicy()->inspectExisting($source->file);
            if ($sourcePath->value === null) {
                continue;
            }
            foreach ($found as [$start, $foundName, $end, $foundKind]) {
                $candidate = $this->schemaQuery->resolveNamedInWorkspace(
                    $workspace,
                    $foundName,
                    $foundKind,
                    $sourcePath->value->relative,
                );
                if (
                    $candidate->status !== SemanticStatus::Ok
                    || $candidate->value === null
                    || $candidate->value->file !== $target->value->file
                ) {
                    continue;
                }
                $references[] = new SchemaReference(
                    $foundName,
                    $foundKind,
                    $source->file,
                    $start,
                    $end,
                );
            }
        }

        usort(
            $references,
            static fn (SchemaReference $left, SchemaReference $right): int => [
                $left->sourceFile,
                $left->contentStart,
                $left->contentEnd,
                $left->kind,
                $left->fileName,
            ] <=> [
                $right->sourceFile,
                $right->contentStart,
                $right->contentEnd,
                $right->kind,
                $right->fileName,
            ],
        );

        return SemanticResult::ok(new SchemaReferences($target->value, $references));
    }
}
