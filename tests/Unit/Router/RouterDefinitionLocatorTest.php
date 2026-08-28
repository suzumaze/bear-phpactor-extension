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

        // 6行目 (0-based 5) の 20桁目: $map->get('index', '/index', '/index'); の '/index'
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(5, 20)
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

        // 7行目 (0-based 6) の 24桁目: '/dashboard'
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(6, 24)
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

        // 8行目 (0-based 7) の 24桁目: '/missing' に対応するクラスは無い
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(7, 24)
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

        // 9行目 (0-based 8) の 21桁目: '/../../Client' は '..' を含むため拒否する
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(8, 21)
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

        // 6行目 (0-based 5) の 27桁目: '/index' (2番目の引数) の閉じクォートの1バイト外
        $response = $tester->mustRequestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            new Position(5, 27)
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

        $typeLocations = $locator->locateDefinition($document, ByteOffset::fromInt(146));

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
