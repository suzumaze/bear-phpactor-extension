<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Router;

use Suzumaze\BearPhpactor\Router\RouterDefinitionLocator;
use Phpactor\Extension\LanguageServerBridge\Converter\LocationConverter;
use Phpactor\Extension\LanguageServerReferenceFinder\Handler\GotoDefinitionHandler;
use Phpactor\LanguageServer\LanguageServerTesterBuilder;
use Phpactor\LanguageServer\Test\LanguageServerTester;
use Phpactor\LanguageServerProtocol\DefinitionParams;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\FilesystemTextDocumentLocator;
use Phpactor\TextDocument\TextDocumentBuilder;
use PHPUnit\Framework\TestCase;

/**
 * aura.route.php のルートパスから Page リソースクラスへの定義ジャンプの受け入れテスト。
 *
 * フィクスチャの N行M桁 で「定義へ移動」を要求すると、期待するファイルの位置が1件返る。
 * フィクスチャの composer.json は psr-4 を "RouterFixture\\": "lib/" としているため、
 * 名前空間の起点が決め打ちでなく psr-4 から来ていることが同時に証明される
 * (src/ を決め打ちした実装なら lib/Resource/Page/Index.php は見つからない)。
 */
final class RouterDefinitionLocatorTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../Fixture/Router';

    private static function fixtureDir(): string
    {
        return (string) realpath(self::FIXTURE_DIR);
    }

    public function testGotoDefinitionFromRoutePath(): void
    {
        $tester = $this->buildTester();
        $tester->initialize();

        $uri = $this->routeFileUri();
        $tester->textDocument()->open($uri, (string) file_get_contents(self::fixtureDir() . '/aura.route.php'));

        // 6行目 (0-based 5) の 14桁目: $map->route('/index', '/index', '/index'); の
        // 第1引数 (ルート名) '/index' の先頭
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(5, 13)
        ));

        $tester->assertSuccess($response);
        self::assertInstanceOf(Location::class, $response->result);
        self::assertSame('file://' . self::fixtureDir() . '/lib/Resource/Page/Index.php', $response->result->uri);
        // クラス名 Index は 7行目 (0-based 6) の 12桁目から始まる
        self::assertSame(6, $response->result->range->start->line);
        self::assertSame(12, $response->result->range->start->character);
    }

    public function testGotoDefinitionFromNestedRoutePath(): void
    {
        $tester = $this->buildTester();
        $tester->initialize();

        $uri = $this->routeFileUri();
        $tester->textDocument()->open($uri, (string) file_get_contents(self::fixtureDir() . '/aura.route.php'));

        // 7行目 (0-based 6) の 14桁目: 第1引数 '/dashboard' の先頭
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(6, 13)
        ));

        $tester->assertSuccess($response);
        self::assertInstanceOf(Location::class, $response->result);
        self::assertSame('file://' . self::fixtureDir() . '/lib/Resource/Page/Dashboard.php', $response->result->uri);
    }

    public function testNoCandidatesWhenResourceClassDoesNotExist(): void
    {
        $tester = $this->buildTester();
        $tester->initialize();

        $uri = $this->routeFileUri();
        $tester->textDocument()->open($uri, (string) file_get_contents(self::fixtureDir() . '/aura.route.php'));

        // 8行目 (0-based 7) の 14桁目: 第1引数 '/missing' に対応するクラスは無い
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(7, 13)
        ));

        $tester->assertSuccess($response);
        self::assertNull($response->result);
    }

    public function testNoCandidatesForParentTraversalRoute(): void
    {
        $tester = $this->buildTester();
        $tester->initialize();

        $uri = $this->routeFileUri();
        $tester->textDocument()->open($uri, (string) file_get_contents(self::fixtureDir() . '/aura.route.php'));

        // 9行目 (0-based 8) の 14桁目: 第1引数 '/../../Client' は '..' を含むため拒否する
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(8, 13)
        ));

        $tester->assertSuccess($response);
        self::assertNull($response->result);
    }

    public function testNoLocationOneBytePastClosingQuote(): void
    {
        $tester = $this->buildTester();
        $tester->initialize();

        $uri = $this->routeFileUri();
        $tester->textDocument()->open($uri, (string) file_get_contents(self::fixtureDir() . '/aura.route.php'));

        // 6行目 (0-based 5) の 21桁目: 第1引数 '/index' の閉じクォートの1バイト外
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(5, 20)
        ));

        $tester->assertSuccess($response);
        self::assertNull($response->result);
    }

    public function testGotoDefinitionTraversesContextPrefix(): void
    {
        $tester = $this->buildTester();
        $tester->initialize();

        $uri = $this->routeFileUri();
        $tester->textDocument()->open($uri, (string) file_get_contents(self::fixtureDir() . '/aura.route.php'));

        // 10行目 (0-based 9) の 14桁目: 第1引数 '/articleRedirector' に対応するクラスは
        // Resource/Page 直下には無く、1階層深い Content/ にある
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(9, 13)
        ));

        $tester->assertSuccess($response);
        self::assertInstanceOf(Location::class, $response->result);
        // macOS のファイルシステムは大小を区別しないため、is_file() では
        // Articleredirector.php も通ってしまう。返ってきたパスの文字列で比べる。
        self::assertSame(
            'file://' . self::fixtureDir() . '/lib/Resource/Page/Content/ArticleRedirector.php',
            $response->result->uri
        );
    }

    public function testGotoDefinitionPreservesCamelCase(): void
    {
        $tester = $this->buildTester();
        $tester->initialize();

        $uri = $this->routeFileUri();
        $tester->textDocument()->open($uri, (string) file_get_contents(self::fixtureDir() . '/aura.route.php'));

        // 11行目 (0-based 10) の 14桁目: 第1引数 '/userProfile' は UserProfile.php に飛ぶ。
        // strtolower を挟む変換 (Userprofile.php) に戻すと、macOS では is_file() が
        // 通ってしまうため、パスの文字列で検証する
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(10, 13)
        ));

        $tester->assertSuccess($response);
        self::assertInstanceOf(Location::class, $response->result);
        self::assertSame(
            'file://' . self::fixtureDir() . '/lib/Resource/Page/UserProfile.php',
            $response->result->uri
        );
    }

    public function testNoCandidatesWhenContextPrefixIsAmbiguous(): void
    {
        $tester = $this->buildTester();
        $tester->initialize();

        $uri = $this->routeFileUri();
        $tester->textDocument()->open($uri, (string) file_get_contents(self::fixtureDir() . '/aura.route.php'));

        // 12行目 (0-based 11) の 14桁目: 第1引数 '/ambiguous' は Content/ と Admin/ の両方に
        // あるため、どちらにも飛ばない (ResourceTargetResolver の仕様)
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(11, 13)
        ));

        $tester->assertSuccess($response);
        self::assertNull($response->result);
    }

    public function testGotoDefinitionFromRouteNameFirstArgument(): void
    {
        $tester = $this->buildTester();
        $tester->initialize();

        $uri = $this->routeFileUri();
        $tester->textDocument()->open($uri, (string) file_get_contents(self::fixtureDir() . '/aura.route.php'));

        // 15行目 (0-based 14) の 14桁目: $map->route('/thing/detail', '/thing/{id}'); の
        // 第1引数 (ルート名) '/thing/detail' の先頭
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(14, 13)
        ));

        $tester->assertSuccess($response);
        self::assertInstanceOf(Location::class, $response->result);
        self::assertSame(
            'file://' . self::fixtureDir() . '/lib/Resource/Page/Thing/Detail.php',
            $response->result->uri
        );
    }

    public function testNoJumpFromUrlPatternSecondArgument(): void
    {
        $tester = $this->buildTester();
        $tester->initialize();

        $uri = $this->routeFileUri();
        $tester->textDocument()->open($uri, (string) file_get_contents(self::fixtureDir() . '/aura.route.php'));

        // 15行目 (0-based 14) の 31桁目: 第2引数 (URLパターン) '/thing/{id}' の先頭。
        // 第2引数から飛ぶと Page/Thing.php に着地してしまう (実アプリの誤爆の形)。
        // Page/Thing.php は実在するので、誤って飛んだらこのテストは赤になる。
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(14, 30)
        ));

        $tester->assertSuccess($response);
        self::assertNull($response->result);
    }

    public function testNoJumpFromUnrelatedUrlPattern(): void
    {
        $tester = $this->buildTester();
        $tester->initialize();

        $uri = $this->routeFileUri();
        $tester->textDocument()->open($uri, (string) file_get_contents(self::fixtureDir() . '/aura.route.php'));

        // 18行目 (0-based 17) の 27桁目: $map->route('/keywords', '/tag/'); の第2引数
        // '/tag/' の先頭。名前とパターンがまったく別系統の対 (実アプリの形)。
        // 将来「URLパターンからも飛ばせたら便利では」という提案に対する防波堤として、
        // 第2引数からは飛ばないことを固定する。'/tag/' から飛ぶと Content/Tag.php に
        // 着地してしまう (実在するので、誤って飛んだらこのテストは赤になる)。
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(17, 26)
        ));

        $tester->assertSuccess($response);
        self::assertNull($response->result);
    }

    public function testGotoDefinitionFromChainedRouteCall(): void
    {
        $tester = $this->buildTester();
        $tester->initialize();

        $uri = $this->routeFileUri();
        $tester->textDocument()->open($uri, (string) file_get_contents(self::fixtureDir() . '/aura.route.php'));

        // 20行目 (0-based 19) の 14桁目: メソッド連鎖と改行を挟む書き方
        // $map->route('/a/b', '/a/{id}')->tokens([...]); の第1引数 '/a/b' の先頭。
        // 位置の勘定 (行内の最初の文字列など) でなく構文木で判定していることの証明。
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(19, 13)
        ));

        $tester->assertSuccess($response);
        self::assertInstanceOf(Location::class, $response->result);
        self::assertSame(
            'file://' . self::fixtureDir() . '/lib/Resource/Page/A/B.php',
            $response->result->uri
        );
    }

    public function testNoJumpFromSecondArgumentOfChainedCall(): void
    {
        $tester = $this->buildTester();
        $tester->initialize();

        $uri = $this->routeFileUri();
        $tester->textDocument()->open($uri, (string) file_get_contents(self::fixtureDir() . '/aura.route.php'));

        // 20行目 (0-based 19) の 22桁目: 連鎖した呼び出しの第2引数 '/a/{id}' の先頭。
        // Page/A.php は実在するので、誤って飛んだらこのテストは赤になる。
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(19, 21)
        ));

        $tester->assertSuccess($response);
        self::assertNull($response->result);
    }

    public function testJumpFromFirstArgumentOfHttpVerbCall(): void
    {
        $tester = $this->buildTester();
        $tester->initialize();

        $uri = $this->routeFileUri();
        $tester->textDocument()->open($uri, (string) file_get_contents(self::fixtureDir() . '/aura.route.php'));

        // 24行目 (0-based 23) の 12桁目: $map->get('/keywords', '/tag/'); の第1引数
        // (ルート名) '/keywords' の先頭。get などの HTTP メソッド別ショートカットも
        // route と同じ引数形 ($name, $path, $handler = null) なので、第1引数から飛ぶ。
        // 実アプリ2本で使われている正規の書き方
        // ($map->get('/rss', '/rss/{format}/'))。
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(23, 11)
        ));

        $tester->assertSuccess($response);
        self::assertInstanceOf(Location::class, $response->result);
        self::assertSame(
            'file://' . self::fixtureDir() . '/lib/Resource/Page/Content/Keywords.php',
            $response->result->uri
        );
    }

    public function testNoJumpFromAttachNamePrefix(): void
    {
        $tester = $this->buildTester();
        $tester->initialize();

        $uri = $this->routeFileUri();
        $tester->textDocument()->open($uri, (string) file_get_contents(self::fixtureDir() . '/aura.route.php'));

        // 28行目 (0-based 27) の 15桁目: $map->attach('/admin/ambiguous', ...) の第1引数
        // (名前の接頭辞) '/admin/ambiguous' の先頭。attach は ($namePrefix, $pathPrefix,
        // callable) の形で、第1引数はルート名ではないため飛ばない。
        // '/admin/ambiguous' を選んだのは、もし誤って attach を受け入れたら
        // Page/Admin/Ambiguous.php (実在) に着地してこのテストが赤になるから。
        // 「対応するファイルが無いから null」で通ってしまう形にしないため。
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(27, 14)
        ));

        $tester->assertSuccess($response);
        self::assertNull($response->result);
    }

    public function testLocatorReturnsClassTypeFromPsr4Prefix(): void
    {
        $locator = new RouterDefinitionLocator();
        $document = TextDocumentBuilder::create(
            (string) file_get_contents(self::fixtureDir() . '/aura.route.php')
        )->uri($this->routeFileUri())->language('php')->build();

        $typeLocations = $locator->locateDefinition($document, ByteOffset::fromInt(139));

        self::assertSame(1, $typeLocations->count());
        self::assertSame('RouterFixture\Resource\Page\Index', $typeLocations->first()->type()->__toString());
    }

    private function buildTester(): LanguageServerTester
    {
        $builder = LanguageServerTesterBuilder::create();
        $builder->addHandler(new GotoDefinitionHandler(
            $builder->workspace(),
            new RouterDefinitionLocator(),
            new LocationConverter(new FilesystemTextDocumentLocator()),
            $builder->clientApi()
        ));

        return $builder->build();
    }

    private function routeFileUri(): string
    {
        return 'file://' . self::fixtureDir() . '/aura.route.php';
    }
}
