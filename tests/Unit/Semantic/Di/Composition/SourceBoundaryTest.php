<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Di\Composition;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSourceIndex;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\DirectoryClassList;

final class SourceBoundaryTest extends TestCase
{
    public function testClassmapDoesNotReadOutsideDirectoriesOrSymlinkedFiles(): void
    {
        $base = sys_get_temp_dir() . '/bear-di-boundary-' . bin2hex(random_bytes(8));
        mkdir($base . '/workspace/src', 0700, true);
        mkdir($base . '/outside', 0700);
        file_put_contents($base . '/outside/Sentinel.php', "<?php\nnamespace Acme;\nclass Sentinel {}\n");
        symlink($base . '/outside/Sentinel.php', $base . '/workspace/src/Sentinel.php');
        file_put_contents($base . '/workspace/composer.json', json_encode(['autoload' => [
            'classmap' => ['../outside', 'src'],
            'psr-4' => ['Acme\\' => 'src'],
        ]], JSON_THROW_ON_ERROR));
        try {
            $classes = new ClassSourceIndex($base . '/workspace');
            self::assertNull($classes->find('Acme\\Sentinel'));
            // A rejected parse is too late: classmap indexing must not have read it either.
            self::assertSame([], (new ReflectionProperty($classes, 'classmap'))->getValue($classes));
            $list = new DirectoryClassList($classes);
            self::assertSame([], $list($base . '/workspace/src'));
            self::assertNull((new ReflectionMethod($list, 'classInFile'))->invoke(
                $list,
                $base . '/workspace/src/Sentinel.php',
            ));
        } finally {
            unlink($base . '/workspace/src/Sentinel.php');
            unlink($base . '/workspace/composer.json');
            unlink($base . '/outside/Sentinel.php');
            rmdir($base . '/workspace/src');
            rmdir($base . '/workspace');
            rmdir($base . '/outside');
            rmdir($base);
        }
    }
}
