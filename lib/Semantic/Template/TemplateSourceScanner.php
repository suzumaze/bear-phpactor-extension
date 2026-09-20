<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Template;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Template\TemplatePathResolver;
use Suzumaze\BearPhpactor\Template\TemplateReference;
use UnexpectedValueException;

/** Enumerates bounded Twig and Qiq source files inside the workspace. */
final readonly class TemplateSourceScanner
{
    private const MAX_TEMPLATE_BYTES = 1_048_576;

    /** @return iterable<TemplateSource> */
    public function scan(WorkspaceContext $workspace, Project $project, ?string $engine = null): iterable
    {
        $engines = $engine === null
            ? [TemplateReference::ENGINE_QIQ, TemplateReference::ENGINE_TWIG]
            : [$engine];
        $files = [];
        foreach ($engines as $candidateEngine) {
            $roots = match ($candidateEngine) {
                TemplateReference::ENGINE_TWIG => TemplatePathResolver::TWIG_ROOTS,
                TemplateReference::ENGINE_QIQ => [TemplatePathResolver::QIQ_ROOT],
                default => [],
            };
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
                    if (!$file->isFile() || !$this->supportsFile($file->getPathname(), $candidateEngine)) {
                        continue;
                    }
                    $path = $workspace->accessPolicy()->inspectExisting($file->getPathname());
                    if ($path->value !== null) {
                        $files[$path->value->absolute . "\0" . $candidateEngine] = [
                            $path->value->absolute,
                            $candidateEngine,
                        ];
                    }
                }
            }
        }
        ksort($files, SORT_STRING);

        foreach ($files as [$file, $candidateEngine]) {
            $contents = @file_get_contents($file, false, null, 0, self::MAX_TEMPLATE_BYTES + 1);
            if ($contents === false || strlen($contents) > self::MAX_TEMPLATE_BYTES) {
                continue;
            }
            yield new TemplateSource($file, $contents, $candidateEngine);
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
