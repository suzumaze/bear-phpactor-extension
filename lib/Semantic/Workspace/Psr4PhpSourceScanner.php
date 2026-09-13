<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Workspace;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Util\PathGuard;
use UnexpectedValueException;

/**
 * Enumerates bounded PHP sources below workspace-contained PSR-4 roots.
 */
final readonly class Psr4PhpSourceScanner
{
    private const DEFAULT_MAX_PHP_BYTES = 1_048_576;

    public function __construct(
        private int $maxPhpBytes = self::DEFAULT_MAX_PHP_BYTES,
    ) {
    }

    /**
     * @param list<string> $needles At least one must occur; an empty list accepts every source.
     * @return iterable<Psr4PhpSource>
     */
    public function scan(WorkspaceContext $workspace, Project $project, array $needles = []): iterable
    {
        $files = [];
        foreach ($project->psr4() as $directories) {
            foreach ($directories as $directory) {
                $base = PathGuard::isAbsolutePath($directory)
                    ? $directory
                    : $project->root() . '/' . $directory;
                $basePath = $workspace->accessPolicy()->inspectExisting($base);
                if ($basePath->value === null || !is_dir($basePath->value->absolute)) {
                    continue;
                }

                try {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator(
                            $basePath->value->absolute,
                            FilesystemIterator::SKIP_DOTS,
                        ),
                        RecursiveIteratorIterator::LEAVES_ONLY,
                        RecursiveIteratorIterator::CATCH_GET_CHILD,
                    );
                } catch (UnexpectedValueException) {
                    continue;
                }

                foreach ($iterator as $file) {
                    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                        continue;
                    }
                    $path = $workspace->accessPolicy()->inspectExisting($file->getPathname());
                    if ($path->value !== null) {
                        $files[$path->value->absolute] = true;
                    }
                }
            }
        }
        ksort($files);

        if ($this->maxPhpBytes < 1) {
            return;
        }
        foreach (array_keys($files) as $file) {
            $contents = @file_get_contents($file, false, null, 0, $this->maxPhpBytes + 1);
            if (
                $contents === false
                || strlen($contents) > $this->maxPhpBytes
                || !$this->containsAny($contents, $needles)
            ) {
                continue;
            }

            yield new Psr4PhpSource($file, $contents);
        }
    }

    /** @param list<string> $needles */
    private function containsAny(string $contents, array $needles): bool
    {
        if ($needles === []) {
            return true;
        }
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($contents, $needle)) {
                return true;
            }
        }

        return false;
    }
}
