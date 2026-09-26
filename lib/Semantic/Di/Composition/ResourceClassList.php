<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di\Composition;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * BEAR.AppMeta `getResourceListGenerator()` over saved files.
 *
 * Follows koriym/psr4list ordering (shallower paths first, then path order) and keeps
 * only classes that are, by source inheritance, ResourceObjects.
 */
final readonly class ResourceClassList
{
    public function __construct(private ClassSourceIndex $classes, private ModuleInterpreter $interpreter)
    {
    }

    /** @return list<array{0: string, 1: string}> */
    public function __invoke(string $namespace, string $dir, string $resourceObject): array
    {
        $real = realpath($dir);
        if ($real === false || !is_dir($real) || !$this->classes->isInsideRoot($real)) {
            return [];
        }
        $dir = $real;
        try {
            $files = [];
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && preg_match('/^.+\.php$/', $file->getPathname()) === 1) {
                    $files[] = $file->getPathname();
                }
            }
        } catch (Throwable) {
            return [];
        }
        usort($files, static function (string $a, string $b): int {
            $depth = count(explode('/', $a)) <=> count(explode('/', $b));

            return $depth !== 0 ? $depth : ($a > $b ? 1 : -1);
        });
        $list = [];
        foreach ($files as $file) {
            $class = $namespace . '\\' . str_replace('/', '\\', substr($file, strlen($dir) + 1, -4));
            if ($this->classes->find($class) !== null && $this->interpreter->isA($class, $resourceObject)) {
                $list[] = [$class, $file];
            }
        }

        return $list;
    }
}
