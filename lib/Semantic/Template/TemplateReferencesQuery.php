<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Template;

use Phpactor\TextDocument\TextDocumentBuilder;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Template\TemplateReferenceScanner;
use Throwable;

/**
 * Finds static Twig/Qiq references resolving to one existing template file.
 */
final class TemplateReferencesQuery
{
    public function __construct(
        private TemplateQuery $templateQuery = new TemplateQuery(),
        private TemplateReferenceScanner $referenceScanner = new TemplateReferenceScanner(),
        private TemplateSourceScanner $sourceScanner = new TemplateSourceScanner(),
    ) {
    }

    /** @return SemanticResult<TemplateReferences|null> */
    public function findInWorkspace(
        WorkspaceContext $workspace,
        string $engine,
        string $name,
        ?string $contextPath = null,
    ): SemanticResult {
        $target = $this->templateQuery->resolveInWorkspace($workspace, $engine, $name, $contextPath);
        if ($target->status !== SemanticStatus::Ok || $target->value === null) {
            return SemanticResult::failure($target->status);
        }
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        $references = [];
        foreach ($this->sourceScanner->scan($workspace, $project->value, $engine) as $source) {
            $document = TextDocumentBuilder::create($source->contents)
                ->uri($source->file)
                ->language($engine)
                ->build();
            $sourcePath = $workspace->accessPolicy()->inspectExisting($source->file);
            if ($sourcePath->value === null) {
                continue;
            }

            try {
                $foundReferences = $this->referenceScanner->references($document);
            } catch (Throwable) {
                continue;
            }
            foreach ($foundReferences as $found) {
                $candidate = $this->templateQuery->resolveInWorkspace(
                    $workspace,
                    $found->engine,
                    $found->name,
                    $sourcePath->value->relative,
                );
                if (
                    $candidate->status !== SemanticStatus::Ok
                    || $candidate->value === null
                    || $candidate->value->file !== $target->value->file
                ) {
                    continue;
                }
                $references[] = new TemplateReferenceUsage(
                    $found->engine,
                    $found->name,
                    $source->file,
                    $found->start,
                    $found->end,
                );
            }
        }

        usort(
            $references,
            static fn (TemplateReferenceUsage $left, TemplateReferenceUsage $right): int => [
                $left->sourceFile,
                $left->contentStart,
                $left->contentEnd,
                $left->name,
            ] <=> [
                $right->sourceFile,
                $right->contentStart,
                $right->contentEnd,
                $right->name,
            ],
        );

        return SemanticResult::ok(new TemplateReferences($target->value, $references));
    }
}
