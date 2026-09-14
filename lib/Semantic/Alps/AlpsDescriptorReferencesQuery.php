<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Alps;

use Phpactor\TextDocument\TextDocumentBuilder;
use Suzumaze\BearPhpactor\Alps\AlpsDescriptorAtOffset;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\Psr4PhpSourceScanner;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Throwable;

/**
 * Finds PHP Alps attributes that resolve to one unambiguous profile descriptor.
 */
final class AlpsDescriptorReferencesQuery
{
    public function __construct(
        private AlpsQuery $alpsQuery = new AlpsQuery(),
        private AlpsDescriptorAtOffset $descriptorAtOffset = new AlpsDescriptorAtOffset(),
        private Psr4PhpSourceScanner $sourceScanner = new Psr4PhpSourceScanner(),
    ) {
    }

    /** @return SemanticResult<AlpsDescriptorReferences|null> */
    public function findInWorkspace(
        WorkspaceContext $workspace,
        string $descriptorId,
        ?string $contextPath = null,
    ): SemanticResult {
        $target = $this->alpsQuery->resolveInWorkspace($workspace, $descriptorId, $contextPath);
        if ($target->status !== SemanticStatus::Ok || $target->value === null) {
            if ($target->status === SemanticStatus::Ambiguous) {
                return SemanticResult::ambiguous(array_map(
                    static fn (AlpsDescriptorResolution $candidate): AlpsDescriptorReferences =>
                        new AlpsDescriptorReferences($candidate, []),
                    $target->candidates,
                ));
            }

            return SemanticResult::failure($target->status);
        }

        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        $references = [];
        foreach ($this->sourceScanner->scan($workspace, $project->value, ['Alps']) as $source) {
            $document = TextDocumentBuilder::create($source->contents)
                ->uri($source->file)
                ->language('php')
                ->build();
            try {
                $found = $this->descriptorAtOffset->references($document);
            } catch (Throwable) {
                continue;
            }

            $sourcePath = $workspace->accessPolicy()->inspectExisting($source->file);
            if ($sourcePath->value === null) {
                continue;
            }
            foreach ($found as [$start, $foundId, $end]) {
                $candidate = $this->alpsQuery->resolveInWorkspace(
                    $workspace,
                    $foundId,
                    $sourcePath->value->relative,
                );
                if (
                    $candidate->status !== SemanticStatus::Ok
                    || $candidate->value === null
                    || $candidate->value->profileFile !== $target->value->profileFile
                    || $candidate->value->offset !== $target->value->offset
                ) {
                    continue;
                }
                $references[] = new AlpsDescriptorReference(
                    $foundId,
                    $source->file,
                    $start,
                    $end,
                );
            }
        }

        usort(
            $references,
            static fn (AlpsDescriptorReference $left, AlpsDescriptorReference $right): int => [
                $left->sourceFile,
                $left->contentStart,
                $left->contentEnd,
                $left->descriptorId,
            ] <=> [
                $right->sourceFile,
                $right->contentStart,
                $right->contentEnd,
                $right->descriptorId,
            ],
        );

        return SemanticResult::ok(new AlpsDescriptorReferences($target->value, $references));
    }
}
