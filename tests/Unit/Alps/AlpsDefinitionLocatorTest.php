<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Alps;

use Suzumaze\BearPhpactor\Alps\AlpsDefinitionLocator;
use Phpactor\Extension\LanguageServerBridge\Converter\LocationConverter;
use Phpactor\Extension\LanguageServerBridge\Converter\PositionConverter;
use Phpactor\Extension\LanguageServerReferenceFinder\Handler\GotoDefinitionHandler;
use Phpactor\LanguageServer\LanguageServerTesterBuilder;
use Phpactor\LanguageServerProtocol\DefinitionParams;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\FilesystemTextDocumentLocator;
use Phpactor\TextDocument\TextDocumentUri;
use PHPUnit\Framework\TestCase;

/**
 * ALPS定義ジャンプの受け入れテスト（LSP層）。
 *
 * `tests/Fixture/Alps/` 配下の各フィクスチャプロジェクトは自前のcomposer.json
 * （psr-4）を持ち、プロジェクトルートはカーソルのあるドキュメントから
 * composer.jsonを上に辿って求める（この拡張自身のディレクトリではないことを
 * 返ってくるURIで証明する）。プロファイルJSONの場所はプロジェクトルート直下の
 * apidoc.xml の `<alps>` 要素で決まる（App1 は var/alps/profile.json を指す）。
 */
final class AlpsDefinitionLocatorTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixture/Alps';

    public function testJumpFromAttributeStringLiteral(): void
    {
        // useで取り込んだ短縮名。着地は profile.json の "id" キーの値
        // （`"id": "doDeleteArticle"` の値の開きクォート、10行目19桁・0起点）。
        $this->assertDefinition(
            'App1',
            'src/Resource/App/AlpsDemo.php',
            '<caret-1>',
            'var/alps/profile.json',
            [10, 19],
        );
    }

    public function testJumpFromFullyQualifiedAttributeWithoutLeadingBackslash(): void
    {
        // useなし完全修飾（先頭バックスラッシュ無し）でも同じ記述子へ飛ぶ。
        // この属性は goArticle を指すので、profile.json 9行目19桁（0起点）に着地。
        $this->assertDefinition('App1', 'src/Resource/App/AlpsDemo.php', '<caret-2>', 'var/alps/profile.json', [9, 19]);
    }

    public function testJumpFromFullyQualifiedAttributeWithLeadingBackslash(): void
    {
        // useなし完全修飾（先頭バックスラッシュ付き）。Ray.Di生成コードの書き方。
        $this->assertDefinition(
            'App1',
            'src/Resource/App/AlpsDemo.php',
            '<caret-3>',
            'var/alps/profile.json',
            [10, 19],
        );
    }

    public function testReturnsNothingWhenDescriptorDoesNotExist(): void
    {
        // プロファイルJSONに存在しないIDを指定しても何も起きない
        $this->assertDefinition('App1', 'src/Resource/App/AlpsDemo.php', '<caret-4>', null);
    }

    public function testReturnsNothingWhenApidocXmlMissing(): void
    {
        // apidoc.xml が無いプロジェクトではこの機能は発火しない（例外は投げない）
        $source = $this->inlineSource();
        $caretOffset = strpos($source, 'doDeleteArticle') + 1;
        self::assertNotFalse($caretOffset);

        $this->assertDefinitionAt($source, $caretOffset, null, 'inline.php', null, 'NoApidoc');
    }

    public function testReturnsNothingWhenApidocXmlHasNoAlpsElement(): void
    {
        // apidoc.xml はあるが <alps> 要素が無い
        $source = $this->inlineSource();
        $caretOffset = strpos($source, 'doDeleteArticle') + 1;
        self::assertNotFalse($caretOffset);

        $this->assertDefinitionAt($source, $caretOffset, null, 'inline.php', null, 'NoAlpsElement');
    }

    public function testReturnsNothingWhenAlpsPathEscapesProjectRoot(): void
    {
        // <alps> に .. を含むパス → PathGuard が弾く
        $source = $this->inlineSource();
        $caretOffset = strpos($source, 'doDeleteArticle') + 1;
        self::assertNotFalse($caretOffset);

        $this->assertDefinitionAt($source, $caretOffset, null, 'inline.php', null, 'EscapePath');
    }

    public function testReturnsNothingWhenAlpsPathIsAbsolute(): void
    {
        // <alps> に絶対パス → PathGuard が弾く（/etc/passwd は実在するため、
        // 弾き漏れたら誤爆してテストが落ちる）
        $source = $this->inlineSource();
        $caretOffset = strpos($source, 'doDeleteArticle') + 1;
        self::assertNotFalse($caretOffset);

        $this->assertDefinitionAt($source, $caretOffset, null, 'inline.php', null, 'AbsolutePath');
    }

    public function testNoLocationOneBytePastClosingQuote(): void
    {
        // インラインドキュメントの 'doDeleteArticle' の閉じクォートの1バイト外に
        // カーソルを置く。ファイルは実在するため、旧実装（終端を含めて発火）なら
        // profile.json に飛んでしまう。
        $source = $this->inlineSource();
        $caretOffset = strpos($source, "'doDeleteArticle'") + strlen("'doDeleteArticle'");
        self::assertNotFalse($caretOffset);

        $this->assertDefinitionAt($source, $caretOffset, null, 'inline.php', null, 'App1');
    }

    /**
     * フィクスチャのドキュメントをLSPワークスペースに開き、markerの位置で
     * textDocument/definition を要求する。
     *
     * @param string $project FIXTURE 配下のプロジェクト名（'App1' など）
     * @param string|null $expectedProfileRelativePath null は候補なし
     * @param array{0: int, 1: int}|null $expectedPosition 着地の [行, 桁] (0起点)。null は位置を検証しない
     */
    private function assertDefinition(
        string $project,
        string $docRelativePath,
        string $marker,
        ?string $expectedProfileRelativePath,
        ?array $expectedPosition = null,
    ): void {
        [$text, $caretOffset] = $this->fixtureWithCaret($project, $docRelativePath, $marker);

        $this->assertDefinitionAt(
            $text,
            $caretOffset,
            $expectedProfileRelativePath,
            $docRelativePath,
            $expectedPosition,
            $project,
        );
    }

    /**
     * ドキュメントテキストとカーソル位置を直接指定して定義ジャンプを検証する。
     * ドキュメントはプロジェクトディレクトリ直下に置く (ProjectLocator が
     * そのプロジェクトの composer.json を辿ってルートを解決できるように)。
     *
     * @param string|null $expectedProfileRelativePath null は候補なし
     * @param array{0: int, 1: int}|null $expectedPosition 着地の [行, 桁] (0起点)。null は位置を検証しない
     */
    private function assertDefinitionAt(
        string $text,
        int $caretOffset,
        ?string $expectedProfileRelativePath,
        string $docRelativePath,
        ?array $expectedPosition = null,
        string $project = 'App1',
    ): void {
        $fixture = self::FIXTURE . '/' . $project;

        $builder = LanguageServerTesterBuilder::create();
        $builder->addHandler(new GotoDefinitionHandler(
            $builder->workspace(),
            new AlpsDefinitionLocator(),
            new LocationConverter(new FilesystemTextDocumentLocator()),
            $builder->clientApi(),
        ));
        $tester = $builder->build();
        $tester->initialize();

        $uri = TextDocumentUri::fromString($fixture . '/' . $docRelativePath)->__toString();
        $builder->workspace()->open(new TextDocumentItem($uri, 'php', 1, $text));

        $response = $tester->requestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            PositionConverter::byteOffsetToPosition(ByteOffset::fromInt($caretOffset), $text),
        ));

        self::assertNotNull($response);
        self::assertNull($response->error);

        if ($expectedProfileRelativePath === null) {
            self::assertNull($response->result);

            return;
        }

        self::assertInstanceOf(Location::class, $response->result);
        self::assertSame(
            TextDocumentUri::fromString($fixture . '/' . $expectedProfileRelativePath)->__toString(),
            $response->result->uri,
        );

        if ($expectedPosition !== null) {
            self::assertSame($expectedPosition[0], $response->result->range->start->line);
            self::assertSame($expectedPosition[1], $response->result->range->start->character);
        }
    }

    /**
     * フィクスチャから <caret> マーカーを取り除いたテキストと、そのマーカー位置の
     * バイトオフセットを返す。複数マーカーは長いものから消す（<caret> が <caret-1>
     * の先頭に一致しないように）。
     *
     * @return array{string, int}
     */
    private function fixtureWithCaret(string $project, string $relativePath, string $marker): array
    {
        $source = file_get_contents(self::FIXTURE . '/' . $project . '/' . $relativePath);
        self::assertNotFalse($source);

        $markerPosition = strpos($source, $marker);
        self::assertNotFalse($markerPosition, sprintf('Marker "%s" not found in %s', $marker, $relativePath));

        $markers = ['<caret-5>', '<caret-4>', '<caret-3>', '<caret-2>', '<caret-1>', '<caret>'];
        $caretOffset = strlen(str_replace($markers, '', substr($source, 0, $markerPosition)));
        $text = str_replace($markers, '', $source);

        return [$text, $caretOffset];
    }

    private function inlineSource(): string
    {
        return "<?php\n\n#[Alps('doDeleteArticle')]\nfinal class Inline\n{\n}\n";
    }
}
