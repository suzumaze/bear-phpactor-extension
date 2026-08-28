<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\JsonSchema;

use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaConventionTypeLocator;
use Phpactor\Extension\LanguageServerBridge\Converter\LocationConverter;
use Phpactor\Extension\LanguageServerBridge\Converter\PositionConverter;
use Phpactor\Extension\LanguageServerReferenceFinder\Handler\TypeDefinitionHandler;
use Phpactor\LanguageServer\LanguageServerTesterBuilder;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\LanguageServerProtocol\TypeDefinitionParams;
use Phpactor\ReferenceFinder\ChainTypeLocator;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\FilesystemTextDocumentLocator;
use Phpactor\TextDocument\TextDocumentBuilder;
use Phpactor\TextDocument\TextDocumentUri;
use PHPUnit\Framework\TestCase;

/**
 * フィクスチャのN行M桁（<caret> マーカー位置）で「型定義へ移動」を要求すると、
 * 期待する JSON Schema ファイルの位置が1件返ることを LSP 層で検証する。
 *
 * クラス宣言名の上の規約ジャンプ (var/json_schema/<ケバブ>.json) は
 * textDocument/definition から textDocument/typeDefinition に載せ替えた
 * (PLAN.md §2.6 の②の退避先)。属性の文字列リテラルからのジャンプは
 * JsonSchemaDefinitionLocatorTest に残っている。
 */
final class JsonSchemaConventionTypeLocatorTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixture/JsonSchema/basic';

    private const REFERENCES_FIXTURE = __DIR__ . '/../../Fixture/References';

    public function testJumpFromClassNameByConvention(): void
    {
        // user.json は "title" キーを持たないので、着地は従来どおり (0,0)。
        $this->assertTypeDefinition(
            'src/Resource/App/User.php',
            '<caret>',
            'var/json_schema/user.json',
            [0, 0],
        );
    }

    public function testJumpFromKebabCasedClassName(): void
    {
        $this->assertTypeDefinition(
            'src/Resource/App/BodyTypeDemo.php',
            '<caret>',
            'var/json_schema/body-type-demo.json',
        );
    }

    public function testJumpFromNestedResourceClass(): void
    {
        $this->assertTypeDefinition(
            'src/Resource/Page/Admin/UserProfile.php',
            '<caret>',
            'var/json_schema/admin/user-profile.json',
        );
    }

    public function testJumpFromKebabSubdirConvention(): void
    {
        // 規則1 (ケバブ・サブディレクトリ)。このクラスはキャメル・サブディレクトリ
        // (cache/articlePreview.json) も実在するが、先頭の規則が採られる。
        $this->assertTypeDefinition(
            'src/Resource/App/Cache/ArticlePreview.php',
            '<caret>',
            'var/json_schema/cache/article-preview.json',
        );
    }

    public function testKebabSubdirWinsOverCamelWhenBothExist(): void
    {
        // 優先順位の固定: cache/article-preview.json と cache/articlePreview.json の
        // 両方が実在するとき、従来の規則 (ケバブ・サブディレクトリ) が採られる。
        // これが崩れると既存利用者の挙動が黙って変わる。
        $this->assertTypeDefinition(
            'src/Resource/App/Cache/ArticlePreview.php',
            '<caret>',
            'var/json_schema/cache/article-preview.json',
        );
    }

    public function testJumpFromCamelSubdirConvention(): void
    {
        // 規則2 (キャメル・サブディレクトリ)。ケバブ (cache/article-list.json) は
        // 実在しないので2番目の候補が採られる。
        $this->assertTypeDefinition(
            'src/Resource/App/Cache/ArticleList.php',
            '<caret>',
            'var/json_schema/cache/articleList.json',
        );
    }

    public function testJumpFromSnakeFlatConvention(): void
    {
        // 規則3 (スネーク・平坦化)。BEAR.Kata の実物 (cache_article_tags.json) と同じ流儀。
        $this->assertTypeDefinition(
            'src/Resource/App/Cache/ArticleTags.php',
            '<caret>',
            'var/json_schema/cache_article_tags.json',
        );
    }

    public function testJumpFromCamelFlatConvention(): void
    {
        // 規則4 (キャメル・平坦化)。セグメントを連結した全体を1つのキャメルケースにする。
        $this->assertTypeDefinition(
            'src/Resource/App/Cache/AuthorProfile.php',
            '<caret>',
            'var/json_schema/cacheAuthorProfile.json',
        );
    }

    public function testJumpFromKebabFlatConvention(): void
    {
        // 規則5 (ケバブ・平坦化)。
        $this->assertTypeDefinition(
            'src/Resource/App/Cache/AuthorList.php',
            '<caret>',
            'var/json_schema/cache-author-list.json',
        );
    }

    public function testReturnsNothingWhenAllConventionsMissingMultiSegment(): void
    {
        // 複数セグメントでも、5つの候補がどれも実在しなければ降りる。
        $this->assertTypeDefinition(
            'src/Resource/App/Cache/Orphan.php',
            '<caret>',
            null,
        );
    }

    public function testReturnsNothingWhenConventionFileMissing(): void
    {
        $this->assertTypeDefinition(
            'src/Resource/App/NoSchema.php',
            '<caret>',
            null,
        );
    }

    public function testConventionJumpFromTopLevelAppResolvesToRootSchema(): void
    {
        // src/ は psr-4 ディレクトリなので、クラス名規約は従来どおり
        // プロジェクトルートの var/json_schema/article.json に着地する。
        $this->assertTypeDefinitionIn(
            self::REFERENCES_FIXTURE,
            'src/Resource/App/Article.php',
            'final class Article',
            'var/json_schema/article.json',
        );
    }

    public function testConventionJumpFromNestedMiniAppDoesNotFallThroughToRoot(): void
    {
        // ミニアプリ (tests/Fake/Mini) のクラス名規約ジャンプは、そのアプリ自身の
        // var/json_schema にしか着地しない。無ければ空 (例外は投げない)。
        // プロジェクトルートのスキーマへフォールスルーしない。
        $this->assertTypeDefinitionIn(
            self::REFERENCES_FIXTURE,
            'tests/Fake/Mini/Resource/App/Article.php',
            'final class Article',
            null,
        );
    }

    public function testConventionJumpFromNestedMiniAppDoesNotFallThroughToRootWithMoreCandidates(): void
    {
        // 候補が増えても同じ: ミニアプリの複数セグメント・クラスは自身の
        // var/json_schema にしか着地しない。ルートにスネーク平坦化の実物
        // (cache_article_preview.json) があっても、ミニアプリ側に無ければ降りる。
        $this->assertTypeDefinitionIn(
            self::REFERENCES_FIXTURE,
            'tests/Fake/Mini/Resource/App/Cache/ArticlePreview.php',
            'final class ArticlePreview',
            null,
        );
    }

    public function testConventionJumpLandsOnTitleKey(): void
    {
        // スキーマの "title" キーの位置に着地する (ファイル先頭 (0,0) ではない)。
        $schema = self::REFERENCES_FIXTURE . '/var/json_schema/article.json';
        $schemaText = (string) file_get_contents($schema);
        $titleOffset = strpos($schemaText, '"title"');
        self::assertNotFalse($titleOffset);
        [$line, $char] = $this->offsetToPosition($titleOffset, $schemaText);

        $this->assertTypeDefinitionIn(
            self::REFERENCES_FIXTURE,
            'src/Resource/App/Article.php',
            'final class Article',
            'var/json_schema/article.json',
            [$line, $char],
        );
    }

    public function testReturnsEmptyInsteadOfThrowingWhenNotApplicable(): void
    {
        // 鎖を壊していないことの固定: 当拡張のTypeLocatorが該当しない位置では
        // 空を返す (CouldNotLocateType を投げない)。ChainTypeLocator が捕まえる
        // のは UnsupportedDocument だけで、例外を投げると組込みの型定義解決
        // (WorseReflectionTypeLocator) まで鎖が届かず、全PHPコードの
        // 「型定義へ移動」が死ぬ。当拡張は列挙の先頭に居るため。
        $locator = new JsonSchemaConventionTypeLocator();

        // リソース名前空間の無いドキュメント (入口の安価な事前判定で降りる)
        $plain = TextDocumentBuilder::create("<?php\nfinal class Foo\n{\n}\n")->language('php')->build();
        self::assertSame(0, $locator->locateTypes($plain, ByteOffset::fromInt(10))->count());

        // リソースクラスだがカーソルがクラス名の上ではない (構文解析して降りる)
        $file = self::FIXTURE . '/src/Resource/App/User.php';
        $resource = (string) file_get_contents($file);
        $methodOffset = strpos($resource, 'onGet');
        self::assertNotFalse($methodOffset);
        $doc = TextDocumentBuilder::create($resource)->uri('file://' . $file)->language('php')->build();
        self::assertSame(0, $locator->locateTypes($doc, ByteOffset::fromInt($methodOffset))->count());
    }

    /**
     * @param string|null $expectedSchemaRelativePath null は候補なし（ファイル不在）
     * @param array{0: int, 1: int}|null $expectedPosition 着地の [行, 桁] (0起点)。null は位置を検証しない
     */
    private function assertTypeDefinition(
        string $relativePath,
        string $marker,
        ?string $expectedSchemaRelativePath,
        ?array $expectedPosition = null,
    ): void {
        [$text, $caretOffset] = $this->fixtureWithCaret($relativePath, $marker);

        $this->assertTypeDefinitionAt(
            $text,
            $caretOffset,
            $expectedSchemaRelativePath,
            $relativePath,
            $expectedPosition,
        );
    }

    /**
     * マーカーを使わないフィクスチャ (References) 用: needle の先頭位置をカーソル
     * として型定義ジャンプを検証する。
     *
     * @param string|null $expectedSchemaRelativePath null は候補なし（ファイル不在）
     * @param array{0: int, 1: int}|null $expectedPosition 着地の [行, 桁] (0起点)。null は位置を検証しない
     */
    private function assertTypeDefinitionIn(
        string $fixture,
        string $relativePath,
        string $needle,
        ?string $expectedSchemaRelativePath,
        ?array $expectedPosition = null,
    ): void {
        $text = (string) file_get_contents($fixture . '/' . $relativePath);
        $caretOffset = strpos($text, $needle);
        self::assertNotFalse($caretOffset, sprintf('Needle "%s" not found in %s', $needle, $relativePath));
        if (str_starts_with($needle, 'final class ')) {
            // カーソルは「クラス名トークンの上」に置く (final の上ではない)
            $caretOffset += strlen('final class ');
        }

        $this->assertTypeDefinitionAt(
            $text,
            $caretOffset,
            $expectedSchemaRelativePath,
            $relativePath,
            $expectedPosition,
            $fixture,
        );
    }

    /**
     * ドキュメントテキストとカーソル位置を直接指定して型定義ジャンプを検証する。
     * ドキュメントはフィクスチャディレクトリ直下に置く (ProjectLocator が
     * basic/composer.json を辿ってプロジェクトルートを解決できるように)。
     *
     * ハンドラには本番と同じ ChainTypeLocator を渡す。当拡張が空を返したとき
     * 鎖が CouldNotLocateType を投げ、ハンドラが null を返す (0件のまま
     * showMessageRequest を送らない) ため。
     *
     * @param string|null $expectedSchemaRelativePath null は候補なし（ファイル不在）
     * @param array{0: int, 1: int}|null $expectedPosition 着地の [行, 桁] (0起点)。null は位置を検証しない
     */
    private function assertTypeDefinitionAt(
        string $text,
        int $caretOffset,
        ?string $expectedSchemaRelativePath,
        string $docRelativePath = 'inline.php',
        ?array $expectedPosition = null,
        string $fixture = self::FIXTURE,
    ): void {
        $builder = LanguageServerTesterBuilder::create();
        $builder->addHandler(new TypeDefinitionHandler(
            $builder->workspace(),
            new ChainTypeLocator([new JsonSchemaConventionTypeLocator()]),
            new LocationConverter(new FilesystemTextDocumentLocator()),
            $builder->clientApi(),
        ));
        $tester = $builder->build();
        $tester->initialize();

        $uri = TextDocumentUri::fromString($fixture . '/' . $docRelativePath)->__toString();
        $builder->workspace()->open(new TextDocumentItem($uri, 'php', 1, $text));

        $response = $tester->requestAndWait('textDocument/typeDefinition', new TypeDefinitionParams(
            new TextDocumentIdentifier($uri),
            PositionConverter::byteOffsetToPosition(ByteOffset::fromInt($caretOffset), $text),
        ));

        self::assertNotNull($response);
        self::assertNull($response->error);

        if ($expectedSchemaRelativePath === null) {
            self::assertNull($response->result);

            return;
        }

        self::assertInstanceOf(Location::class, $response->result);
        self::assertSame(
            TextDocumentUri::fromString($fixture . '/' . $expectedSchemaRelativePath)->__toString(),
            $response->result->uri,
        );

        if ($expectedPosition !== null) {
            self::assertSame($expectedPosition[0], $response->result->range->start->line);
            self::assertSame($expectedPosition[1], $response->result->range->start->character);
        }
    }

    /**
     * バイトオフセットを [行, 桁] (0起点) に変換する。
     *
     * @return array{0: int, 1: int}
     */
    private function offsetToPosition(int $byteOffset, string $text): array
    {
        $before = substr($text, 0, $byteOffset);
        $lastNewline = strrpos($before, "\n");

        return [
            substr_count($before, "\n"),
            $lastNewline === false ? $byteOffset : $byteOffset - $lastNewline - 1,
        ];
    }

    /**
     * フィクスチャから <caret> マーカーを取り除いたテキストと、そのマーカー位置の
     * バイトオフセットを返す。複数マーカーは長いものから消す（<caret> が <caret-1>
     * の先頭に一致しないように）。
     *
     * @return array{string, int}
     */
    private function fixtureWithCaret(string $relativePath, string $marker): array
    {
        $source = file_get_contents(self::FIXTURE . '/' . $relativePath);
        self::assertNotFalse($source);

        $markerPosition = strpos($source, $marker);
        self::assertNotFalse($markerPosition, sprintf('Marker "%s" not found in %s', $marker, $relativePath));

        $markers = ['<caret-5>', '<caret-4>', '<caret-3>', '<caret-2>', '<caret-1>', '<caret>'];
        $caretOffset = strlen(str_replace($markers, '', substr($source, 0, $markerPosition)));
        $text = str_replace($markers, '', $source);

        return [$text, $caretOffset];
    }
}
