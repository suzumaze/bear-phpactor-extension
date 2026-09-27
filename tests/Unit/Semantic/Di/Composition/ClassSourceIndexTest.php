<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Di\Composition;

use Suzumaze\BearPhpactor\Semantic\Di\Composition\BearPackageComposition;

/**
 * The fixture's vendor/autoload.php and its autoload `files` entry echo a marker if they ever run.
 */
final class ClassSourceIndexTest extends CompositionTestCase
{
    public function testResolvesRootAndInstalledPackageClassesFromMetadata(): void
    {
        self::assertSame('src/Module/AppModule.php', $this->classes->find('\\Acme\\Shop\\Module\\AppModule')?->path);
        self::assertSame(
            'vendor/bear/package/src/Context/ProdModule.php',
            $this->classes->find('BEAR\\Package\\Context\\ProdModule')?->path,
        );
        self::assertSame(
            'BEAR\\Resource\\ResourceObject',
            $this->classes->find('Acme\\Shop\\Resource\\App\\AbstractBase')?->parent,
        );
        self::assertNull($this->classes->find('Acme\\Shop\\Missing'));
    }

    public function testNeverIncludesComposerAutoloadOrItsFiles(): void
    {
        $this->expectOutputString('');
        $before = get_included_files();

        (new BearPackageComposition($this->classes, $this->interpreter))('Acme\\Shop', 'cli-prod-app');

        self::assertArrayNotHasKey('acme_sentinel_ran', $GLOBALS);
        $fixture = self::fixtureRoot() . '/';
        self::assertSame([], array_values(array_filter(
            array_diff(get_included_files(), $before),
            static fn (string $file): bool => str_starts_with($file, $fixture),
        )));
    }
    public function testEvidenceRootDoesNotChangeSourceFileIdentity(): void
    {
        $root = realpath(dirname(__DIR__, 4) . '/Fixture/DiComposition');
        self::assertIsString($root);
        $index = new \Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSourceIndex($root, dirname($root));
        $source = $index->find('Acme\\Shop\\Module\\AppModule');
        self::assertNotNull($source);
        self::assertSame('DiComposition/src/Module/AppModule.php', $source->path);
        self::assertSame($root . '/src/Module/AppModule.php', $index->absolutePath($source->path));
    }
}
