<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\JsonSchema;

use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaDefinitionLocator;
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
 * フィクスチャのN行M桁（<caret> マーカー位置）で「定義へ移動」を要求すると、
 * 期待する JSON Schema ファイルの位置が1件返ることを LSP 層で検証する。
 */
final class JsonSchemaDefinitionLocatorTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixture/JsonSchema/basic';

    public function testJumpFromAttributeStringLiteral(): void
    {
        $this->assertDefinition(
            'src/Resource/App/SchemaDemo.php',
            '<caret-1>',
            'var/json_schema/user.json',
        );
    }

    public function testJumpFromNamedSchemaArgument(): void
    {
        $this->assertDefinition(
            'src/Resource/App/SchemaDemo.php',
            '<caret-2>',
            'var/json_schema/user.json',
        );
    }

    public function testJumpFromParamsArgumentToRequestSchema(): void
    {
        $this->assertDefinition(
            'src/Resource/App/SchemaDemo.php',
            '<caret-3>',
            'var/json_validate/user-params.json',
        );
    }

    public function testReturnsNothingWhenSchemaFileMissing(): void
    {
        $this->assertDefinition(
            'src/Resource/App/SchemaDemo.php',
            '<caret-4>',
            null,
        );
    }

    public function testRejectsParentTraversalFileName(): void
    {
        $this->assertDefinition(
            'src/Resource/App/SchemaDemo.php',
            '<caret-5>',
            null,
        );
    }

    public function testNoLocationOneBytePastClosingQuote(): void
    {
        // インラインドキュメントの 'user.json' の閉じクォートの1バイト外にカーソルを置く。
        // ファイルは実在するため、旧実装 (終端を含めて発火) なら user.json に飛んでしまう。
        $source = "<?php\n\n#[JsonSchema('user.json')]\nfinal class Inline\n{\n}\n";
        $caretOffset = strpos($source, "'user.json'") + strlen("'user.json'");
        self::assertNotFalse($caretOffset);

        $this->assertDefinitionAt($source, $caretOffset, null);
    }

    /**
     * @param string|null $expectedSchemaRelativePath null は候補なし（ファイル不在）
     * @param array{0: int, 1: int}|null $expectedPosition 着地の [行, 桁] (0起点)。null は位置を検証しない
     */
    private function assertDefinition(
        string $relativePath,
        string $marker,
        ?string $expectedSchemaRelativePath,
        ?array $expectedPosition = null,
    ): void {
        [$text, $caretOffset] = $this->fixtureWithCaret($relativePath, $marker);

        $this->assertDefinitionAt($text, $caretOffset, $expectedSchemaRelativePath, $relativePath, $expectedPosition);
    }

    /**
     * ドキュメントテキストとカーソル位置を直接指定して定義ジャンプを検証する。
     * ドキュメントはフィクスチャディレクトリ直下に置く (ProjectLocator が
     * basic/composer.json を辿ってプロジェクトルートを解決できるように)。
     *
     * @param string|null $expectedSchemaRelativePath null は候補なし（ファイル不在）
     * @param array{0: int, 1: int}|null $expectedPosition 着地の [行, 桁] (0起点)。null は位置を検証しない
     */
    private function assertDefinitionAt(
        string $text,
        int $caretOffset,
        ?string $expectedSchemaRelativePath,
        string $docRelativePath = 'inline.php',
        ?array $expectedPosition = null,
        string $fixture = self::FIXTURE,
    ): void {
        $builder = LanguageServerTesterBuilder::create();
        $builder->addHandler(new GotoDefinitionHandler(
            $builder->workspace(),
            new JsonSchemaDefinitionLocator(),
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
