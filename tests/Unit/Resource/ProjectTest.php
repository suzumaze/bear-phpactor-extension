<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Resource;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use PHPUnit\Framework\TestCase;

/**
 * 名前空間の起点が composer.json の psr-4 から解決されることの証明。
 * フィクスチャのプレフィックス "Acme\Blog\" は lib/ のどこにも書かれていない。
 */
final class ProjectTest extends TestCase
{
    private static function fixtureDir(): string
    {
        return dirname(__DIR__, 2) . '/Fixture/Resource';
    }

    public function testResolvesClassNameFromPsr4(): void
    {
        $project = Project::locate(self::fixtureDir() . '/src/Client.php');
        self::assertNotNull($project);

        $user = ResourceUri::fromString('app://self/user');
        self::assertNotNull($user);
        self::assertSame('Acme\Blog\Resource\App\User', $project->classFqn($user));
        self::assertSame(self::fixtureDir() . '/src/Resource/App/User.php', $project->classFile($user));
    }

    public function testResolvesNestedAndPageClassFromPsr4(): void
    {
        $project = Project::locate(self::fixtureDir() . '/src/Client.php');
        self::assertNotNull($project);

        $posts = ResourceUri::fromString('app://self/blog/posts');
        self::assertSame('Acme\Blog\Resource\App\Blog\Posts', $project->classFqn($posts));
        self::assertSame(self::fixtureDir() . '/src/Resource/App/Blog/Posts.php', $project->classFile($posts));

        $index = ResourceUri::fromString('page://self/index');
        self::assertSame('Acme\Blog\Resource\Page\Index', $project->classFqn($index));
        self::assertSame(self::fixtureDir() . '/src/Resource/Page/Index.php', $project->classFile($index));
    }

    public function testResourceClassesAreScannedFromPsr4Directory(): void
    {
        $project = Project::locate(self::fixtureDir() . '/src/Client.php');
        self::assertNotNull($project);

        $classes = $project->resourceClasses();

        self::assertArrayHasKey('app://self/user', $classes);
        self::assertArrayHasKey('app://self/blog/posts', $classes);
        self::assertArrayHasKey('page://self/index', $classes);
        // ResourceObject を継承しないクラスは候補に含めない
        self::assertArrayNotHasKey('app://self/notAResource', $classes);
        // 独自の基底クラス (MyResourceObject) を継承するクラスは候補に含めない
        self::assertArrayNotHasKey('app://self/extendsCustomBase', $classes);
        // docblock に 'extends ResourceObject' と書いてあるだけのクラスは候補に含めない
        self::assertArrayNotHasKey('app://self/docblockMention', $classes);
        // 別名インポート経由の間接継承 (実アプリの形) は候補に含める
        self::assertArrayHasKey('app://self/indirectAlias', $classes);
        // 2段の連鎖 (孫) も候補に含める
        self::assertArrayHasKey('app://self/indirectGrandchild', $classes);
        // 辿っても ResourceObject に行き着かないクラスは候補に含めない
        self::assertArrayNotHasKey('app://self/indirectNotResource', $classes);
        // 循環する継承 (A extends B かつ B extends A) は候補に含めない
        self::assertArrayNotHasKey('app://self/cycleA', $classes);
        self::assertArrayNotHasKey('app://self/cycleB', $classes);
        self::assertSame('Acme\Blog\Resource\App\User', $classes['app://self/user']);
    }

    public function testSkipsComposerJsonWithoutPsr4AndContinuesUpward(): void
    {
        // nested/composer.json は psr-4 を持たないためスキップし、上の
        // tests/Fixture/Resource/composer.json (psr-4 あり) をルートとする。
        $project = Project::locate(self::fixtureDir() . '/nested/marker.txt');
        self::assertNotNull($project);
        self::assertSame(self::fixtureDir(), $project->root());
    }

    public function testClassFileRejectsParentTraversal(): void
    {
        $project = Project::locate(self::fixtureDir() . '/src/Client.php');
        self::assertNotNull($project);

        $uri = ResourceUri::fromString('app://self/../../Client');
        self::assertNotNull($uri);
        // Resource ディレクトリの外 (src/Client.php) へ着地させない
        self::assertNull($project->classFile($uri));
    }

    public function testClassFileCandidatesFindsContextPrefixedClasses(): void
    {
        $project = Project::locate(self::fixtureDir() . '/src/Client.php');
        self::assertNotNull($project);

        $x = ResourceUri::fromString('page://self/x');
        self::assertNotNull($x);
        // 直接のクラスは無い (classFile はパスを返すだけで実在は見ない)
        $direct = $project->classFile($x);
        self::assertNotNull($direct);
        self::assertFileDoesNotExist($direct);

        $candidates = $project->classFileCandidates($x);
        self::assertCount(2, $candidates);
        self::assertSame(self::fixtureDir() . '/src/Resource/Page/Admin/X.php', $candidates[0]['file']);
        self::assertSame('Acme\Blog\Resource\Page\Admin\X', $candidates[0]['fqn']);
        self::assertSame(self::fixtureDir() . '/src/Resource/Page/Content/X.php', $candidates[1]['file']);
        self::assertSame('Acme\Blog\Resource\Page\Content\X', $candidates[1]['fqn']);
    }

    public function testClassFileCandidatesEmptyWhenDirectClassExists(): void
    {
        $project = Project::locate(self::fixtureDir() . '/src/Client.php');
        self::assertNotNull($project);

        $y = ResourceUri::fromString('page://self/y');
        self::assertNotNull($y);
        // 直接のクラスが在る場合は候補を出さない (ロケータは直接を優先する)
        self::assertNotNull($project->classFile($y));
        self::assertSame([], $project->classFileCandidates($y));
    }

    public function testClassFileCandidatesRejectsParentTraversal(): void
    {
        $project = Project::locate(self::fixtureDir() . '/src/Client.php');
        self::assertNotNull($project);

        $uri = ResourceUri::fromString('app://self/../../Client');
        self::assertNotNull($uri);
        // 直接のパスが不正なときは深い階層も探さない
        self::assertSame([], $project->classFileCandidates($uri));
    }
}
