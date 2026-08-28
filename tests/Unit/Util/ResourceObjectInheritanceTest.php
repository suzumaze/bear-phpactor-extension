<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Util;

use Suzumaze\BearPhpactor\Util\PhpClassDeclaration;
use Suzumaze\BearPhpactor\Util\ProjectLocator;
use Suzumaze\BearPhpactor\Util\ResourceObjectInheritance;
use PHPUnit\Framework\TestCase;

/**
 * 継承の連鎖を辿る共通部品 (ResourceObjectInheritance) のテスト。
 * フィクスチャは tests/Fixture/Resource (psr-4: Acme\Blog\ => src/)。
 */
final class ResourceObjectInheritanceTest extends TestCase
{
    private static function fixtureDir(): string
    {
        return dirname(__DIR__, 2) . '/Fixture/Resource';
    }

    private static function inheritance(): ResourceObjectInheritance
    {
        $found = ProjectLocator::locate(self::fixtureDir() . '/src/Client.php');
        self::assertNotNull($found);

        return new ResourceObjectInheritance($found['root'], $found['psr4']);
    }

    public function testDirectInheritanceIsAResource(): void
    {
        $class = PhpClassDeclaration::find(self::fixtureDir() . '/src/Resource/App/User.php');
        self::assertNotNull($class);
        self::assertTrue(self::inheritance()->extendsResourceObject($class));
    }

    public function testAliasImportIndirectInheritanceIsAResource(): void
    {
        // 実アプリの形: use X as Y; class Foo extends Y (PLAN.md §2.17)。
        // getResolvedName() は別名を解決して完全修飾名を返すため、短い名前の
        // 文字列比較では拾えない。
        $class = PhpClassDeclaration::find(self::fixtureDir() . '/src/Resource/App/IndirectAlias.php');
        self::assertNotNull($class);
        self::assertTrue(self::inheritance()->extendsResourceObject($class));
    }

    public function testTwoLevelChainIsAResource(): void
    {
        // 孫: IndirectGrandchild → ArticleChild → ArticleBase → ResourceObject
        $class = PhpClassDeclaration::find(self::fixtureDir() . '/src/Resource/App/IndirectGrandchild.php');
        self::assertNotNull($class);
        self::assertTrue(self::inheritance()->extendsResourceObject($class));
    }

    public function testChainThatNeverReachesResourceObjectIsNotAResource(): void
    {
        // 否定側の対照: 親は実在するが ResourceObject に行き着かない。
        // 判定を広げたとき、広がりすぎないことの検査。
        $class = PhpClassDeclaration::find(self::fixtureDir() . '/src/Resource/App/IndirectNotResource.php');
        self::assertNotNull($class);
        self::assertFalse(self::inheritance()->extendsResourceObject($class));
    }

    public function testCycleTerminatesAndIsNotAResource(): void
    {
        // A extends B かつ B extends A。止まらずに false を返すこと。
        // 編集途中の壊れたコードはエディタの中に日常的に存在する
        // (このリポジトリで25分ハングした過去がある)。
        $inheritance = self::inheritance();
        $a = PhpClassDeclaration::find(self::fixtureDir() . '/src/Resource/App/CycleA.php');
        $b = PhpClassDeclaration::find(self::fixtureDir() . '/src/Resource/App/CycleB.php');
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertFalse($inheritance->extendsResourceObject($a));
        self::assertFalse($inheritance->extendsResourceObject($b));
    }

    public function testDepthLimitStopsLongChains(): void
    {
        // 深さの上限 (20段)。21段の連鎖はリソースと判定しない。
        // フィクスチャを21個置く代わりに、一時ディレクトリに連鎖を生成する。
        $tmp = sys_get_temp_dir() . '/bear-lsp-depth-' . bin2hex(random_bytes(4));
        $src = $tmp . '/src';
        mkdir($src, 0777, true);
        $composerJson = "{\n"
            . "    \"autoload\": {\n"
            . "        \"psr-4\": {\n"
            . "            \"Depth\\\\Test\\\\\": \"src/\"\n"
            . "        }\n"
            . "    }\n"
            . "}\n";
        file_put_contents($tmp . '/composer.json', $composerJson);

        // C1 extends C2 ... C21 extends C22, C22 extends ResourceObject (21段)
        $n = 22;
        for ($i = 1; $i <= $n; $i++) {
            $parent = $i === $n ? 'BEAR\Resource\ResourceObject' : sprintf('C%d', $i + 1);
            $body = sprintf(
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace Depth\\Test;\n\nfinal class C%d extends %s\n{\n}\n",
                $i,
                $parent
            );
            file_put_contents(sprintf('%s/src/C%d.php', $tmp, $i), $body);
        }

        try {
            $inheritance = new ResourceObjectInheritance($tmp, ['Depth\\Test\\' => ['src']]);
            $class = PhpClassDeclaration::find($tmp . '/src/C1.php');
            self::assertNotNull($class);
            self::assertFalse($inheritance->extendsResourceObject($class));
        } finally {
            foreach (glob($tmp . '/src/*.php') ?: [] as $f) {
                unlink($f);
            }
            unlink($tmp . '/composer.json');
            rmdir($tmp . '/src');
            rmdir($tmp);
        }
    }
}
