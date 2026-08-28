<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Resource;

use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaDefinitionLocator;
use Suzumaze\BearPhpactor\Resource\ReferenceFinder\ResourceDefinitionLocator;
use Suzumaze\BearPhpactor\Resource\ReferenceFinder\ResourceReferenceFinder;
use Suzumaze\BearPhpactor\Resource\Model\ResourceTargetResolver;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Router\RouterDefinitionLocator;
use Suzumaze\BearPhpactor\Sql\SqlDefinitionLocator;
use Microsoft\PhpParser\Parser;
use Phpactor\Extension\LanguageServerBridge\Converter\LocationConverter;
use Phpactor\Extension\LanguageServerBridge\TextDocument\WorkspaceTextDocumentLocator;
use Phpactor\Extension\LanguageServerReferenceFinder\Handler\ReferencesHandler;
use Phpactor\LanguageServer\LanguageServerTesterBuilder;
use Phpactor\LanguageServer\Test\LanguageServerTester;
use Phpactor\LanguageServer\Test\ProtocolFactory;
use Phpactor\LanguageServerProtocol\Location as LspLocation;
use Phpactor\LanguageServerProtocol\ReferencesRequest;
use Phpactor\ReferenceFinder\ChainDefinitionLocationProvider;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocument;
use Phpactor\TextDocument\TextDocumentBuilder;
use Phpactor\WorseReflection\Core\Cache\NullCache;
use Phpactor\WorseReflection\Core\Exception\SourceNotFound;
use Phpactor\WorseReflection\Core\Name;
use Phpactor\WorseReflection\Core\SourceCodeLocator;
use Phpactor\WorseReflection\ReflectorBuilder;
use Phpactor\WorseReferenceFinder\WorseReflectionDefinitionLocator;
use PHPUnit\Framework\TestCase;

/**
 * 受け入れテスト: リソースクラス宣言名・リソースURI文字列の上で参照検索を要求
 * すると、そのリソースを参照する箇所が返る。
 *
 * LSP層のテストは本物の ReferencesHandler を経由して組む (PLAN.md §2.10 の
 * 再演を避ける: 本番に存在しない組み立てでテストが緑になるのを防ぐ)。
 */
final class ResourceReferenceFinderTest extends TestCase
{
    private static function fixtureDir(): string
    {
        return dirname(__DIR__, 2) . '/Fixture/References';
    }

    public function testFindsReferencesFromClassDeclaration(): void
    {
        // src/Resource/App/Article.php のクラス名の上で参照検索すると、
        // #[Link]・#[Embed]・resource->post() の3か所が返る
        $response = $this->requestReferences(
            'src/Resource/App/Article.php',
            'final class Article',
            false
        );

        self::assertSame($this->expectedReferences(), $this->asReferences($response->result));
    }

    public function testFindsSameReferencesFromUriString(): void
    {
        // Articles.php の 'app://self/article{?id}' の文字列の中から同じ要求を
        // しても、同じ集合が返る (往復一致: 参照結果の上で定義ジャンプすると
        // 必ず起点のクラスへ戻る)
        $response = $this->requestReferences(
            'src/Resource/App/Articles.php',
            'app://self/article',
            false
        );

        self::assertSame($this->expectedReferences(), $this->asReferences($response->result));
    }

    public function testMiniAppArticleIsNotMixedIn(): void
    {
        // ミニアプリ (tests/Fake/Mini) の同名リソースのクラス名の上で参照検索すると、
        // Mini 内の参照だけが返る。src/ 側の3件は混ざらない (参照の同一性は
        // URI文字列ではなく解決先のファイルで判定するため。PLAN.md §2.11)
        $response = $this->requestReferences(
            'tests/Fake/Mini/Resource/App/Article.php',
            'final class Article',
            false
        );

        $callerFile = self::fixtureDir() . '/tests/Fake/Mini/Resource/App/Caller.php';
        $callerContent = (string) file_get_contents($callerFile);
        $literal = "'app://self/article'";
        [$line, $char] = $this->positionOf($literal, $callerContent);

        self::assertSame([
            ['file://' . $callerFile, $line, $char, $line, $char + strlen($literal)],
        ], $this->asReferences($response->result));
    }

    public function testReturnsEmptyForUnreferencedResource(): void
    {
        // 誰からも参照されないリソースは空が返る。例外は投げない
        $response = $this->requestReferences(
            'src/Resource/App/Orphan.php',
            'final class Orphan',
            false
        );

        self::assertSame([], $this->asReferences($response->result));
    }

    public function testIncludeDeclarationAddsClassItself(): void
    {
        // includeDeclaration: true (VS Code の「すべての参照を検索」が送る) のとき、
        // 定義ロケータの連鎖の先頭結果が「宣言」として一覧に足される。規約ジャンプ
        // (クラス宣言名 → var/json_schema/<名前>.json) は定義チェーンから
        // textDocument/typeDefinition へ移った (PLAN.md §2.6 の②の退避先) ため、
        // クラス宣言名の上では組込みの WorseReflectionDefinitionLocator が答え、
        // 宣言の枠にクラス自身が入る。
        $response = $this->requestReferences(
            'src/Resource/App/Article.php',
            'final class Article',
            true
        );

        $expected = $this->expectedReferences();
        // 組込みの位置はクラス宣言ノード全体 (final キーワードから閉じ括弧まで)。
        $classFile = self::fixtureDir() . '/src/Resource/App/Article.php';
        $classContent = (string) file_get_contents($classFile);
        [$startLine, $startChar] = $this->positionOf('final class Article', $classContent);
        $classEnd = strrpos($classContent, '}') + 1;
        self::assertNotFalse($classEnd);
        $before = substr($classContent, 0, $classEnd);
        $endLine = substr_count($before, "\n");
        $endChar = $classEnd - (int) strrpos("\n" . $before, "\n");
        $expected[] = [
            'file://' . $classFile,
            $startLine,
            $startChar,
            $endLine,
            $endChar,
        ];
        // ReferencesHandler は Location の一覧をソートして返す (ファイルパス順)
        usort($expected, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        self::assertSame($expected, $this->asReferences($response->result));
    }

    public function testSkipsDocumentWithoutResourceMarkers(): void
    {
        // リソースの目印 (app:// / page:// / ResourceObject) が1つも無く、
        // /Resource/App/ や /Resource/Page/ の下にも無いドキュメントは、
        // findReferences() 冒頭の安価な事前判定で降りる。構文解析にすら
        // 入らない (パーサーのモックが呼ばれないことで担保)。
        // ※ これは「カーソル位置が無関係かどうか」とは独立の入口の判定。
        // 「カーソルが無関係な位置」の検証は testDoesNotScanProjectAtUnrelatedPosition
        // が担う (こちらはリソースの目印を含むドキュメントでないと意味が無い)。
        // ※ 目印が無くても /Resource/App/ /Resource/Page/ の下にあるドキュメントは
        // 通過する (間接継承のリソースは本文に目印を持たない。PLAN.md §2.17)。
        // 通過した非リソースは後段の継承チェックが空で落とす。
        $finder = new ResourceReferenceFinder(
            new StringLiteralAtOffset($this->parserThatMustNotRun()),
            new ResourceTargetResolver(),
            $this->parserThatMustNotRun(),
        );
        $document = TextDocumentBuilder::create("<?php\n\$x = 'hello';\n")
            ->uri('file://' . self::fixtureDir() . '/src/Domain/ArticleBase.php')
            ->language('php')
            ->build();

        $generator = $finder->findReferences($document, ByteOffset::fromInt(10));

        self::assertSame([], iterator_to_array($generator));
        self::assertFalse($generator->getReturn());
    }

    public function testDoesNotScanProjectAtUnrelatedPosition(): void
    {
        // 本物のリソースクラス (#[Link] を持つ Articles.php) は入口の事前判定を
        // 必ず通過する。そこでカーソルが無関係な位置 (メソッド名の上) にある
        // とき、targetAtOffset() が null を返して走査に入らない。空が返り、
        // 例外も出ないことを確認する。
        //
        // ※ (b) の経路はパーサーを使うため「走査に入らない」ことをモックで
        // 担保できない (PLAN.md §2.11 受け入れ基準5の注記)。空が返ることと
        // ジェネレータの返り値 (false = 鎖を続ける) で検証する。
        $response = $this->requestReferences(
            'src/Resource/App/Articles.php',
            'onGet',
            false
        );

        self::assertSame([], $this->asReferences($response->result));
    }

    public function testReturnsEmptyForTestClassInResourceDir(): void
    {
        // リソースディレクトリの下に置かれているが ResourceObject を継承しない
        // クラス (BEAR.Kata の tests/Resource/App/ と tests/Resource/Page/ にある
        // PHPUnit テストクラス38個と同じ形) では、クラス名の上でも参照検索は
        // 空を返す。継承の判定は構文解析で行うため、ファイルの場所だけでは
        // リソースクラスと区別できない (PLAN.md §2.11)。フィクスチャは
        // ResourceObject に言及しているので入口の事前判定は通過し、継承
        // チェックが空を返すことが検証される。
        $response = $this->requestReferences(
            'tests/Resource/App/ArticleTest.php',
            'final class ArticleTest',
            false
        );

        self::assertSame([], $this->asReferences($response->result));
    }

    public function testFindsReferencesFromIndirectClassDeclaration(): void
    {
        // 別名インポート経由の間接継承 (PLAN.md §2.17 の欠陥の修正)。
        // IndirectArticle extends ValidArticleBase (ArticleBase の別名) で、
        // ArticleBase は ResourceObject を継承する。クラス宣言名の上で参照検索
        // すると IndirectCaller.php の #[Link] が返る。修正前は0件だった。
        $response = $this->requestReferences(
            'src/Resource/App/IndirectArticle.php',
            'final class IndirectArticle',
            false
        );

        $file = self::fixtureDir() . '/src/Resource/App/IndirectCaller.php';
        $content = (string) file_get_contents($file);
        $literal = "'app://self/indirectArticle'";
        [$line, $char] = $this->positionOf($literal, $content);

        self::assertSame([
            ['file://' . $file, $line, $char, $line, $char + strlen($literal)],
        ], $this->asReferences($response->result));
    }

    public function testReturnsEmptyForIndirectNonResourceClass(): void
    {
        // 否定側の対照: Resource/App/ にあるが、辿っても ResourceObject に
        // 行き着かないクラス (IndirectNotResource extends PlainBase) では、
        // クラス名の上でも参照検索は空を返す。判定を広げたとき、広がりすぎ
        // ないことの検査。
        $response = $this->requestReferences(
            'src/Resource/App/IndirectNotResource.php',
            'final class IndirectNotResource',
            false
        );

        self::assertSame([], $this->asReferences($response->result));
    }

    public function testAmbiguousContextPrefixSiteIsNotAReference(): void
    {
        // page://self/x は Page/Content/X.php と Page/Admin/X.php の2件に
        // 解決しうる (コンテキスト接頭辞)。'page://self/x' を書いたサイト
        // (AmbiguousPage.php) はどちらのクラスの参照にも数えられない
        // (定義ジャンプ側と同じく解決しなかったものとして扱う。PLAN.md §2.11)。
        $contentX = $this->requestReferences('src/Resource/Page/Content/X.php', 'final class X', false);
        self::assertSame([], $this->asReferences($contentX->result));

        $adminX = $this->requestReferences('src/Resource/Page/Admin/X.php', 'final class X', false);
        self::assertSame([], $this->asReferences($adminX->result));
    }

    public function testFindsReferencesFromClassDeclarationWithUnsavedEdits(): void
    {
        // 未保存の編集 (クラス宣言より前に2行足した) があると、ディスクと
        // ドキュメントの行がずれる。(b) はディスクではなくドキュメントを
        // 構文解析するため、同じファイル・同じクラス名・同じカーソル位置
        // (クラス名 Article の上) で、ディスクどおりと編集ありの両方とも
        // 同じ3件が返る。修正前は編集ありが0件になった (PLAN.md §2.10 の再演)。
        $file = self::fixtureDir() . '/src/Resource/App/Article.php';
        $disk = (string) file_get_contents($file);
        $edited = preg_replace('/^<\?php/', "<?php\n// unsaved line\n// another unsaved line", $disk, 1);
        self::assertNotSame($disk, $edited);

        // エディタの未保存バッファをワークスペースに流し込む。didChange は
        // このバージョンのテストヘルパーが壊れている (生配列を送るがハンドラは
        // 型付きオブジェクトを期待する) ため、didOpen でドキュメントを置換する
        // (Workspace::open は同じURIの文書を上書きする)。ReferencesHandler は
        // workspace からドキュメントを引くので、本番と同じ経路で未保存テキスト
        // が届く。
        $tester = $this->createTester();
        $tester->textDocument()->open('file://' . $file, $edited);

        [$line, $char] = $this->positionOf('final class Article', $edited);
        $char += strlen('final class ');

        $response = $tester->requestAndWait(ReferencesRequest::METHOD, [
            'textDocument' => ProtocolFactory::textDocumentIdentifier('file://' . $file),
            'position' => ProtocolFactory::position($line, $char),
            'context' => ['includeDeclaration' => false],
        ]);

        self::assertNotNull($response);
        $tester->assertSuccess($response);
        self::assertSame($this->expectedReferences(), $this->asReferences($response->result));
    }

    public function testFinderReturnsFalseSoBuiltinFinderStillRuns(): void
    {
        // 組込みの IndexedReferenceFinder を殺していないことの担保。ジェネレータが
        // true を返すと ChainReferenceFinder が鎖を止める (ChainReferenceFinder.php:25-33)。
        // 参照を1件でもyieldした後も false で終わること。
        $file = self::fixtureDir() . '/src/Resource/App/Article.php';
        $content = (string) file_get_contents($file);
        $finder = new ResourceReferenceFinder(new StringLiteralAtOffset());

        $offset = strpos($content, 'final class Article');
        self::assertNotFalse($offset);

        $generator = $finder->findReferences(
            TextDocumentBuilder::create($content)->uri('file://' . $file)->language('php')->build(),
            ByteOffset::fromInt($offset + strlen('final class '))
        );

        self::assertNotEmpty(iterator_to_array($generator));
        self::assertFalse($generator->getReturn());
    }

    /**
     * クラス名の上とURI文字列の中からの要求で共通の期待集合。
     *
     * @return list<array{0: string, 1: int, 2: int, 3: int, 4: int}>
     */
    private function expectedReferences(): array
    {
        $dir = self::fixtureDir();
        $expected = [];

        // #[Link(rel: 'goArticle', href: 'app://self/article{?id}')]
        $file = $dir . '/src/Resource/App/Articles.php';
        $content = (string) file_get_contents($file);
        $literal = "'app://self/article{?id}'";
        [$line, $char] = $this->positionOf($literal, $content);
        $expected[] = ['file://' . $file, $line, $char, $line, $char + strlen($literal)];

        // $this->resource->post('app://self/article', ...)
        $file = $dir . '/src/Resource/Page/Admin/Article.php';
        $content = (string) file_get_contents($file);
        $literal = "'app://self/article'";
        [$line, $char] = $this->positionOf($literal, $content);
        $expected[] = ['file://' . $file, $line, $char, $line, $char + strlen($literal)];

        // #[Embed(rel: '_self', src: 'app://self/article')]
        $file = $dir . '/src/Resource/Page/Article.php';
        $content = (string) file_get_contents($file);
        $literal = "'app://self/article'";
        [$line, $char] = $this->positionOf($literal, $content);
        $expected[] = ['file://' . $file, $line, $char, $line, $char + strlen($literal)];

        // ReferencesHandler は Location の一覧をソートして返す (ファイルパス順)
        usort($expected, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return $expected;
    }

    /**
     * 参照検索の要求を本物の ReferencesHandler に投げる。
     *
     * @return \Phpactor\LanguageServer\Core\Rpc\ResponseMessage
     */
    private function requestReferences(
        string $relativeFile,
        string $needle,
        bool $includeDeclaration,
    ): \Phpactor\LanguageServer\Core\Rpc\ResponseMessage {
        $file = self::fixtureDir() . '/' . $relativeFile;
        $content = (string) file_get_contents($file);
        [$line, $char] = $this->positionOf($needle, $content);
        if (str_starts_with($needle, 'final class ')) {
            // カーソルは「クラス名トークンの上」に置く (final の上ではない)
            $char += strlen('final class ');
        }

        $tester = $this->createTester();
        $response = $tester->requestAndWait(ReferencesRequest::METHOD, [
            'textDocument' => ProtocolFactory::textDocumentIdentifier('file://' . $file),
            'position' => ProtocolFactory::position($line, $char),
            'context' => ['includeDeclaration' => $includeDeclaration],
        ]);

        self::assertNotNull($response);
        $tester->assertSuccess($response);

        return $response;
    }

    /**
     * LSPレスポンスの result (Locationの配列) を [uri, start行, start列, end行, end列]
     * の配列に畳む。
     *
     * @return list<array{0: string, 1: int, 2: int, 3: int, 4: int}>
     */
    private function asReferences(mixed $result): array
    {
        self::assertIsArray($result);
        $out = [];
        foreach ($result as $location) {
            self::assertInstanceOf(LspLocation::class, $location);
            $out[] = [
                $location->uri,
                $location->range->start->line,
                $location->range->start->character,
                $location->range->end->line,
                $location->range->end->character,
            ];
        }

        return $out;
    }

    private function createTester(): LanguageServerTester
    {
        $builder = LanguageServerTesterBuilder::create();

        // 本番と同じ組み立て: 定義ロケータは当拡張の4機能の連鎖 (定義ジャンプ側の
        // 登録順) に、組込みの WorseReflectionDefinitionLocator を続ける。規約
        // ジャンプが定義チェーンから抜けた (textDocument/typeDefinition へ移動)
        // ため、includeDeclaration: true のときクラス宣言名の上では組込みが
        // 答え、クラス自身が宣言として返る。
        $tester = $builder->addHandler(new ReferencesHandler(
            $builder->workspace(),
            new ResourceReferenceFinder(new StringLiteralAtOffset()),
            new ChainDefinitionLocationProvider([
                new ResourceDefinitionLocator(new StringLiteralAtOffset()),
                new RouterDefinitionLocator(),
                new SqlDefinitionLocator(),
                new JsonSchemaDefinitionLocator(),
                new WorseReflectionDefinitionLocator($this->fixtureReflector(), new NullCache()),
            ]),
            new LocationConverter(new WorkspaceTextDocumentLocator($builder->workspace())),
            $builder->clientApi(),
            30.0,
            10.0,
        ))->build();

        // フィクスチャの全ファイルをワークスペースに開く
        // (LocationConverter が範囲を変換するため)。結果に出うるのは .php と
        // var/json_schema/*.json の2種類。
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::fixtureDir(), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'json'], true)) {
                continue;
            }
            if ($file->getFilename() === 'composer.json') {
                continue;
            }
            $tester->textDocument()->open(
                'file://' . $file->getPathname(),
                (string) file_get_contents($file->getPathname())
            );
        }

        return $tester;
    }

    /**
     * フィクスチャの psr-4 に従ってクラス名 → ファイルを引くリフレクタ。
     * 組込みの WorseReflectionDefinitionLocator が「クラス自身」を答えるために
     * 使う (本番では phpactor のコンテナが組み立てる)。
     */
    private function fixtureReflector(): \Phpactor\WorseReflection\Reflector
    {
        $fixtureDir = self::fixtureDir();

        return ReflectorBuilder::create()
            ->addLocator(new class ([
                'Acme\Refs\\' => [$fixtureDir . '/src', $fixtureDir . '/tests'],
                'BEAR\Resource\\' => [$fixtureDir . '/vendor/BEAR/Resource'],
            ]) implements SourceCodeLocator {
                /**
                 * @param array<string, list<string>> $psr4 プレフィックス (末尾 '\') → ディレクトリ一覧
                 */
                public function __construct(private array $psr4)
                {
                }

                public function locate(Name $name): TextDocument
                {
                    $fqn = (string) $name;
                    foreach ($this->psr4 as $prefix => $dirs) {
                        if (!str_starts_with($fqn, $prefix)) {
                            continue;
                        }
                        foreach ($dirs as $dir) {
                            $file = $dir . '/' . str_replace('\\', '/', substr($fqn, strlen($prefix))) . '.php';
                            if (is_file($file)) {
                                return TextDocumentBuilder::fromUri($file)->build();
                            }
                        }
                    }

                    throw new SourceNotFound(sprintf('Could not find source for "%s"', $fqn));
                }
            }, 1)
            ->build();
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

    private function parserThatMustNotRun(): Parser
    {
        $parser = $this->createMock(Parser::class);
        $parser->expects(self::never())->method('parseSourceFile');

        return $parser;
    }
}
