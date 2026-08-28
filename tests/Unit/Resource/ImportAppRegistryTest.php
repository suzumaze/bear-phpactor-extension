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
}
