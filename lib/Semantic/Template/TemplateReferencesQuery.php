<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Template;

use FilesystemIterator;
use Phpactor\TextDocument\TextDocumentBuilder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Template\TemplatePathResolver;
use Suzumaze\BearPhpactor\Template\TemplateReference;
use Suzumaze\BearPhpactor\Template\TemplateReferenceScanner;
use Throwable;
use UnexpectedValueException;

/**
 * Finds static Twig/Qiq references resolving to one existing template file.
 */
final class TemplateReferencesQuery
{
    private const MAX_TEMPLATE_BYTES = 1_048_576;

    public function __construct(
        private TemplateQuery $templateQuery = new TemplateQuery(),
        private TemplateReferenceScanner $referenceScanner = new TemplateReferenceScanner(),
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
        foreach ($this->templateSources($workspace, $project->value, $engine) as [$sourceFile, $contents]) {
            $document = TextDocumentBuilder::create($contents)
                ->uri($sourceFile)
                ->language($engine)
                ->build();
            $sourcePath = $workspace->accessPolicy()->inspectExisting($sourceFile);
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
                    $sourceFile,
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

    /** @return iterable<array{string,string}> */
    private function templateSources(WorkspaceContext $workspace, Project $project, string $engine): iterable
    {
        $roots = match ($engine) {
            TemplateReference::ENGINE_TWIG => TemplatePathResolver::TWIG_ROOTS,
            TemplateReference::ENGINE_QIQ => [TemplatePathResolver::QIQ_ROOT],
            default => [],
        };
        $files = [];
        foreach ($roots as $root) {
            $rootPath = $workspace->accessPolicy()->inspectExisting($project->root() . '/' . $root);
            if ($rootPath->value === null || !is_dir($rootPath->value->absolute)) {
                continue;
            }
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($rootPath->value->absolute, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY,
                    RecursiveIteratorIterator::CATCH_GET_CHILD,
                );
            } catch (UnexpectedValueException) {
                continue;
            }
            foreach ($iterator as $file) {
                if (!$file->isFile() || !$this->supportsFile($file->getPathname(), $engine)) {
                    continue;
                }
                $path = $workspace->accessPolicy()->inspectExisting($file->getPathname());
                if ($path->value !== null) {
                    $files[$path->value->absolute] = true;
                }
            }
        }
        ksort($files);

        foreach (array_keys($files) as $file) {
            $contents = @file_get_contents($file, false, null, 0, self::MAX_TEMPLATE_BYTES + 1);
            if ($contents === false || strlen($contents) > self::MAX_TEMPLATE_BYTES) {
                continue;
            }
            yield [$file, $contents];
        }
    }

    private function supportsFile(string $file, string $engine): bool
    {
        $lower = strtolower($file);

        return match ($engine) {
            TemplateReference::ENGINE_TWIG => str_ends_with($lower, '.html.twig'),
            TemplateReference::ENGINE_QIQ => str_ends_with($lower, '.php'),
            default => false,
        };
    }
}
