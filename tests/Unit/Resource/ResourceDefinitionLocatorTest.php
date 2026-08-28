<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Resource;

use Suzumaze\BearPhpactor\Resource\ReferenceFinder\ResourceDefinitionLocator;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Phpactor\Extension\LanguageServerBridge\Converter\LocationConverter;
use Phpactor\Extension\LanguageServerBridge\TextDocument\WorkspaceTextDocumentLocator;
use Phpactor\Extension\LanguageServerReferenceFinder\Handler\GotoDefinitionHandler;
use Phpactor\LanguageServer\LanguageServerTesterBuilder;
use Phpactor\LanguageServer\Test\LanguageServerTester;
use Phpactor\LanguageServer\Test\ProtocolFactory;
use Phpactor\LanguageServerProtocol\DefinitionRequest;
use Phpactor\ReferenceFinder\Exception\CouldNotLocateDefinition;
use Phpactor\LanguageServerProtocol\Location as LspLocation;
use Phpactor\LanguageServerProtocol\MessageActionItem;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;
use PHPUnit\Framework\TestCase;

use function Amp\Promise\wait;

/**
 * 受け入れテスト: フィクスチャの uri('app://self/user') の文字列上で
 * 定義へ移動を要求すると、src/Resource/App/User.php の位置が1件返る。
 */
final class ResourceDefinitionLocatorTest extends TestCase
{
    private static function fixtureDir(): string
    {
        return dirname(__DIR__, 2) . '/Fixture/Resource';
    }

    public function testGoesToAppResourceClass(): void
    {
        $location = $this->requestDefinition('app://self/user');

        self::assertSame('file://' . self::fixtureDir() . '/src/Resource/App/User.php', $location->uri);
        // クラス名 User は 9行目 (0-based 8) の 12桁目から始まる (ファイル先頭ではない)
        self::assertSame(8, $location->range->start->line);
        self::assertSame(12, $location->range->start->character);
    }

    public function testGoesToPageResourceClass(): void
    {
        $location = $this->requestDefinition('page://self/index');

        self::assertSame('file://' . self::fixtureDir() . '/src/Resource/Page/Index.php', $location->uri);
        // クラス名 Index は 9行目 (0-based 8) の 12桁目から始まる
        self::assertSame(8, $location->range->start->line);
        self::assertSame(12, $location->range->start->character);
    }

    public function testGoesToNestedResourceClass(): void
    {
        $location = $this->requestDefinition('app://self/blog/posts');

        self::assertSame('file://' . self::fixtureDir() . '/src/Resource/App/Blog/Posts.php', $location->uri);
        // クラス名 Posts は 9行目 (0-based 8) の 12桁目から始まる
        self::assertSame(8, $location->range->start->line);
        self::assertSame(12, $location->range->start->character);
    }

    public function testReturnsNullForMissingResource(): void
    {
        $response = $this->requestDefinitionResponse('app://self/missing');

        self::assertNull($response->result);
    }

    public function testRejectsParentTraversalInUri(): void
    {
        // app://self/../../Client は src/Resource/App/../../Client.php となり
        // Resource ディレクトリの外 (実在する src/Client.php) に着地するため拒否する
        $response = $this->requestDefinitionResponse('app://self/../../Client');

        self::assertNull($response->result);
    }

    public function testAmbiguousUriYieldsNothingRatherThanAPicker(): void
    {
        // page://self/x は直接のクラスが無く、Page/Content/X.php と Page/Admin/X.php の
        // 2件が候補になる (コンテキスト接頭辞。PLAN.md §2.8)。
        //
        // 以前はその2件を返していたが、phpactor は候補が複数だと Location の配列ではなく
        // window/showMessageRequest の選択プロンプトを出し、クライアントが答えないと
        // CouldNotLocateType を投げる。実機のログに
        //   "Client did not return an action item"
        // が2回続けて残った (URI の中をクリックし直しただけ)。VS Code では
        // スタックトレース付きの赤いエラー通知になる。
        //
        // したがって曖昧なときは黙って諦める。1つに決めつけると誤った場所へ飛ばすため。
        $clientPath = self::fixtureDir() . '/src/Client.php';
        $content = (string) file_get_contents($clientPath);
        $byteOffset = strpos($content, 'page://self/x');
        self::assertNotFalse($byteOffset);

        $locator = new ResourceDefinitionLocator(new StringLiteralAtOffset());

        $this->expectException(CouldNotLocateDefinition::class);
        $locator->locateDefinition(
            TextDocumentBuilder::create($content)->uri('file://' . $clientPath)->language('php')->build(),
            ByteOffset::fromInt($byteOffset)
        );
    }

    public function testAmbiguousUriSendsNoRequestToTheClient(): void
    {
        // LSP 越しでも同じ。選択プロンプトを送らず、結果は null。
        // これを送ってしまうと、答えなかったときにエラー通知が出る。
        [$tester, $clientUri, $content] = $this->createTester();
        [$line, $char] = $this->positionOf('page://self/x', $content);

        $response = $tester->requestAndWait(DefinitionRequest::METHOD, [
            'textDocument' => ProtocolFactory::textDocumentIdentifier($clientUri),
            'position' => ProtocolFactory::position($line, $char),
        ]);

        self::assertNotNull($response);
        $tester->assertSuccess($response);
        self::assertNull($response->result);
    }

    public function testPrefersDirectResourceOverContextPrefix(): void
    {
        // page://self/y は Page/Y.php が直接あるので、1件だけ返る (挙動不変)
        $location = $this->requestDefinition('page://self/y');

        self::assertSame('file://' . self::fixtureDir() . '/src/Resource/Page/Y.php', $location->uri);
        self::assertSame(8, $location->range->start->line);
        self::assertSame(12, $location->range->start->character);
    }

    public function testGoesToImportedAppResource(): void
    {
        // app://tags/api/search は ImportApp('tags', 'Acme\Tags', ...) により
        // vendor/acme/tags-core パッケージ内のリソースクラスへ向く
        [$tester, $clientUri, $content] = $this->createTester();
        // 取り込み先パッケージのファイルもワークスペースに開く
        // (LocationConverter が内容を解決するため)
        $importedFile = self::fixtureDir() . '/vendor/acme/tags-core/src/Resource/App/Api/Search.php';
        $tester->textDocument()->open('file://' . $importedFile, (string) file_get_contents($importedFile));

        [$line, $char] = $this->positionOf('app://tags/api/search', $content);
        $response = $tester->requestAndWait(DefinitionRequest::METHOD, [
            'textDocument' => ProtocolFactory::textDocumentIdentifier($clientUri),
            'position' => ProtocolFactory::position($line, $char),
        ]);
        self::assertNotNull($response);
        $tester->assertSuccess($response);
        self::assertInstanceOf(LspLocation::class, $response->result);
        self::assertSame('file://' . $importedFile, $response->result->uri);
        self::assertSame(8, $response->result->range->start->line);
        self::assertSame(12, $response->result->range->start->character);
    }

    public function testReturnsNullForUnknownImportedHost(): void
    {
        // 対応表に無いホストは従来どおり候補なし
        $response = $this->requestDefinitionResponse('app://unknown/api/search');

        self::assertNull($response->result);
    }

    private function requestDefinition(string $needle): LspLocation
    {
        $response = $this->requestDefinitionResponse($needle);

        self::assertInstanceOf(LspLocation::class, $response->result);

        return $response->result;
    }

    private function requestDefinitionResponse(string $needle): \Phpactor\LanguageServer\Core\Rpc\ResponseMessage
    {
        [$tester, $clientUri, $content] = $this->createTester();
        [$line, $char] = $this->positionOf($needle, $content);

        $response = $tester->requestAndWait(DefinitionRequest::METHOD, [
            'textDocument' => ProtocolFactory::textDocumentIdentifier($clientUri),
            'position' => ProtocolFactory::position($line, $char),
        ]);

        self::assertNotNull($response);
        $tester->assertSuccess($response);

        return $response;
    }

    /**
     * @return array{LanguageServerTester, string, string, LanguageServerTesterBuilder}
     */
    private function createTester(): array
    {
        $clientPath = self::fixtureDir() . '/src/Client.php';
        $clientUri = 'file://' . $clientPath;
        $content = (string) file_get_contents($clientPath);

        $builder = LanguageServerTesterBuilder::create();
        $tester = $builder->addHandler(new GotoDefinitionHandler(
            $builder->workspace(),
            new ResourceDefinitionLocator(new StringLiteralAtOffset()),
            new LocationConverter(new WorkspaceTextDocumentLocator($builder->workspace())),
            $builder->clientApi(),
        ))->build();
        $tester->textDocument()->open($clientUri, $content);

        // 定義先ファイルもワークスペースに開く (LocationConverter が内容を解決するため)
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::fixtureDir() . '/src/Resource', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $tester->textDocument()->open(
                    'file://' . $file->getPathname(),
                    (string) file_get_contents($file->getPathname())
                );
            }
        }

        return [$tester, $clientUri, $content, $builder];
    }

    /**
     * テキスト中の needle の先頭位置を [行, 列] (0始まり) で返す。
     *
     * @return array{0: int, 1: int}
     */
    private function positionOf(string $needle, string $content): array
    {
        $byteOffset = strpos($content, $needle);
        self::assertNotFalse($byteOffset, sprintf('Needle "%s" not found in fixture', $needle));
        $before = substr($content, 0, $byteOffset);
        $lastNewline = strrpos($before, "\n");

        return [
            substr_count($before, "\n"),
            $lastNewline === false ? $byteOffset : $byteOffset - $lastNewline - 1,
        ];
    }
}
