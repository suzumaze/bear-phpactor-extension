<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Resource;

use Suzumaze\BearPhpactor\Resource\Model\ImportAppRegistry;
use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use PHPUnit\Framework\TestCase;

/**
 * ImportApp ('tags', 'Acme\Tags', ...) の対応表と、取り込まれた別アプリの
 * リソースクラス解決のテスト。
 *
 * フィクスチャ: src/Module/App/CustomResourceUriModule.php に
 * new ImportApp('tags', 'Acme\Tags', ...) と
 * new ImportApp($dynamicHost, 'Acme\Ignored', ...) がある。
 * vendor/composer/installed.json が acme/tags-core の psr-4 を引く。
 */
final class ImportAppRegistryTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryRoots as $root) {
            $this->removeTree($root);
        }
    }

    private static function fixtureDir(): string
    {
        return dirname(__DIR__, 2) . '/Fixture/Resource';
    }

    public function testResolvesImportedHostToPackageResource(): void
    {
        $uri = ResourceUri::fromString('app://tags/api/search');
        self::assertNotNull($uri);

        $candidate = ImportAppRegistry::forProject(self::fixtureDir())->resolve($uri);

        self::assertNotNull($candidate);
        self::assertSame(
            self::fixtureDir() . '/vendor/acme/tags-core/src/Resource/App/Api/Search.php',
            $candidate['file']
        );
        self::assertSame('Acme\Tags\Resource\App\Api\Search', $candidate['fqn']);
    }

    public function testUnknownHostReturnsNull(): void
    {
        $uri = ResourceUri::fromString('app://unknown/api/search');
        self::assertNotNull($uri);

        self::assertNull(ImportAppRegistry::forProject(self::fixtureDir())->resolve($uri));
    }

    public function testIgnoresImportAppWithNonLiteralFirstArgument(): void
    {
        // 第1引数が文字列リテラルでない new ImportApp($dynamicHost, 'Acme\Ignored', ...) は
        // 対応表に載らない
        $uri = ResourceUri::fromString('app://ignored/api/search');
        self::assertNotNull($uri);

        self::assertNull(ImportAppRegistry::forProject(self::fixtureDir())->resolve($uri));
    }

    public function testScansProjectWhoseAncestorIsHidden(): void
    {
        $root = $this->temporaryProject('/.hidden/workspace');
        $uri = ResourceUri::fromString('app://tags/api/search');
        self::assertNotNull($uri);

        $candidate = ImportAppRegistry::forProject($root)->resolve($uri);

        self::assertNotNull($candidate);
        self::assertSame($root . '/packages/tags/Resource/App/Api/Search.php', $candidate['file']);
    }

    public function testSkipsUnreadableChildDirectory(): void
    {
        $root = $this->temporaryProject('/workspace');
        $unreadable = $root . '/var/unreadable';
        self::assertTrue(mkdir($unreadable, 0777, true));
        self::assertNotFalse(file_put_contents($unreadable . '/Ignored.php', '<?php'));
        self::assertTrue(chmod($unreadable, 0000));
        $uri = ResourceUri::fromString('app://tags/api/search');
        self::assertNotNull($uri);

        try {
            $candidate = ImportAppRegistry::forProject($root)->resolve($uri);
        } finally {
            chmod($unreadable, 0700);
        }

        self::assertNotNull($candidate);
    }

    private function temporaryProject(string $suffix): string
    {
        $temporaryRoot = sys_get_temp_dir() . '/bear-import-app-' . bin2hex(random_bytes(8));
        $this->temporaryRoots[] = $temporaryRoot;
        $root = $temporaryRoot . $suffix;
        self::assertTrue(mkdir($root . '/src', 0777, true));
        self::assertTrue(mkdir($root . '/packages/tags/Resource/App/Api', 0777, true));
        self::assertNotFalse(file_put_contents(
            $root . '/composer.json',
            '{"autoload":{"psr-4":{"Acme\\\\Tags\\\\":"packages/tags/"}}}',
        ));
        self::assertNotFalse(file_put_contents(
            $root . '/src/AppModule.php',
            <<<'PHP'
<?php

namespace Acme\App;

use BEAR\Package\Module\Import\ImportApp;

new ImportApp('tags', 'Acme\Tags', 'app');
PHP,
        ));
        self::assertNotFalse(file_put_contents(
            $root . '/packages/tags/Resource/App/Api/Search.php',
            '<?php namespace Acme\Tags\Resource\App\Api; final class Search {}',
        ));

        return $root;
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
        }
        rmdir($path);
    }
}
