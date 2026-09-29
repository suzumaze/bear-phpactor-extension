<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di\Composition;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Ray.MediaQuery `ClassesInDirectories::list()` over saved files.
 *
 * Files are listed in the order the filesystem returns them, exactly as the runtime
 * iterator does, so binding order matches on the same checkout.
 */
final readonly class DirectoryClassList
{
    public function __construct(private ClassSourceIndex $classes)
    {
    }

    /** @return list<string> */
    public function __invoke(string ...$directories): array
    {
        $classes = [];
        foreach ($directories as $directory) {
            $real = realpath($directory);
            if ($real === false || !is_dir($real) || !$this->classes->isInsideRoot($real)) {
                continue;
            }
            $directory = $real;
            $files = [];
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                );
                foreach ($iterator as $file) {
                    if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                        $files[] = $file->getPathname();
                    }
                }
            } catch (Throwable) {
                continue;
            }
            foreach ($files as $file) {
                $class = $this->classInFile($file);
                if ($class !== null && $this->classes->find($class) !== null) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    private function classInFile(string $file): ?string
    {
        $real = realpath($file);
        if ($real === false || !$this->classes->isInsideRoot($real)) {
            return null;
        }
        $contents = @file_get_contents($real);
        if ($contents === false) {
            return null;
        }
        $namespace = preg_match('/^\s*namespace\s+([^;{\s]+)/m', $contents, $m) === 1 ? $m[1] . '\\' : '';
        if (preg_match(ClassSourceIndex::DECLARATION, $contents, $c) !== 1) {
            return null;
        }

        return $namespace . $c[1];
    }
}
