<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Sql;

use Suzumaze\BearPhpactor\Sql\SqlDefinitionLocator;
use Phpactor\Extension\LanguageServerBridge\Converter\LocationConverter;
use Phpactor\Extension\LanguageServerBridge\Converter\PositionConverter;
use Phpactor\Extension\LanguageServerReferenceFinder\Handler\GotoDefinitionHandler;
use Phpactor\LanguageServer\LanguageServerTesterBuilder;
use Phpactor\LanguageServerProtocol\DefinitionParams;
use Phpactor\LanguageServerProtocol\Location as LspLocation;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\ReferenceFinder\ChainDefinitionLocationProvider;
use Phpactor\TextDocument\FilesystemTextDocumentLocator;
use Phpactor\TextDocument\TextDocumentUri;
use PHPUnit\Framework\TestCase;

/**
 * SQL定義ジャンプの受け入れテスト（LSP層）。
 *
 * フィクスチャ `tests/Fixture/Sql/App1/` は自前のcomposer.json（psr-4）を持つ
 * 独立したプロジェクトで、プロジェクトルートはカーソルのあるドキュメントから
 * composer.jsonを上に辿って求める（この拡張自身のディレクトリではないことを
 * 返ってくるURIで証明する）。
 */
final class SqlDefinitionLocatorTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $projectRoot = realpath(__DIR__ . '/../../Fixture/Sql/App1');
        self::assertNotFalse($projectRoot, 'Fixture project not found');
        $this->projectRoot = $projectRoot;
    }

    public function testGotoDefinitionFromDbQueryAttribute(): void
    {
        // カーソルを 'point_distance' の名前に置く（第1引数、type: 'row' は後続）
        $location = $this->requestDefinition(
            'src/Query/PointQueryInterface.php',
            "'point_distance'",
            1
        );

        self::assertInstanceOf(LspLocation::class, $location);
        self::assertSame($this->sqlFileUri('point_distance.sql'), $location->uri);
        self::assertSame(0, $location->range->start->line);
        self::assertSame(0, $location->range->start->character);
    }

    public function testGotoDefinitionFromQueryAnnotation(): void
    {
        // PHPDocの @Query("point_distance") の名前にカーソルを置く
        $location = $this->requestDefinition(
            'src/Query/LegacyPointQueryInterface.php',
            'point_distance'
        );

        self::assertInstanceOf(LspLocation::class, $location);
        self::assertSame($this->sqlFileUri('point_distance.sql'), $location->uri);
        self::assertSame(0, $location->range->start->line);
        self::assertSame(0, $location->range->start->character);
    }

    public function testNoLocationWhenDbQuerySqlFileDoesNotExist(): void
    {
        $location = $this->requestDefinition(
            'src/Query/MissingQueryInterface.php',
            "'missing_query'",
            1
        );

        self::assertNull($location);
    }

    public function testNoLocationWhenQueryAnnotationSqlFileDoesNotExist(): void
    {
        $location = $this->requestDefinition(
            'src/Query/MissingQueryInterface.php',
            'missing_query'
        );

        self::assertNull($location);
    }

    public function testNoLocationWhenCursorIsOnAttributeName(): void
    {
        $location = $this->requestDefinition(
            'src/Query/PointQueryInterface.php',
            'DbQuery'
        );

        self::assertNull($location);
    }

    public function testNoLocationWhenCursorIsOnNonFirstArgument(): void
    {
        // type: 'row' の値にカーソルを置いても、クエリ名は第1引数のみ対象
        $location = $this->requestDefinition(
            'src/Query/PointQueryInterface.php',
            "'row'",
            1
        );

        self::assertNull($location);
    }

    public function testNoLocationWhenCursorIsOnAnnotationTagNotName(): void
    {
        // @Query タグ自体（名前の外）にカーソルがあっても飛ばない
        $location = $this->requestDefinition(
            'src/Query/LegacyPointQueryInterface.php',
            '@Query'
        );

        self::assertNull($location);
    }

    public function testNoLocationWhenQueryNameEscapesSqlDirectory(): void
    {
        // '../escape' は var/db/sql の外へ出ようとするため拒否する
        $location = $this->requestDefinition(
            'src/Query/EscapeQueryInterface.php',
            "'../escape'",
            1
        );

        self::assertNull($location);
    }

    public function testNoLocationOneBytePastClosingQuote(): void
    {
        // 閉じクォートの1バイト外は文字列の内側ではないため発火しない
        $location = $this->requestDefinition(
            'src/Query/PointQueryInterface.php',
            "'point_distance'",
            strlen("'point_distance'")
        );

        self::assertNull($location);
    }

    /**
     * フィクスチャのドキュメントをLSPワークスペースに開き、needleの
     * needleOffsetバイト後（クエリ名の上）で textDocument/definition を要求する。
     */
    private function requestDefinition(string $fixtureRelativePath, string $needle, int $needleOffset = 0): ?LspLocation
    {
        $path = $this->projectRoot . '/' . $fixtureRelativePath;
        $text = (string) file_get_contents($path);
        $uri = TextDocumentUri::fromString($path)->__toString();

        $builder = LanguageServerTesterBuilder::create();
        $tester = $builder
            ->addHandler(new GotoDefinitionHandler(
                $builder->workspace(),
                new ChainDefinitionLocationProvider([
                    new SqlDefinitionLocator(),
                ]),
                new LocationConverter(new FilesystemTextDocumentLocator()),
                $builder->clientApi(),
            ))
            ->build();

        $tester->textDocument()->open($uri, $text);

        $needlePosition = strpos($text, $needle);
        self::assertNotFalse($needlePosition, sprintf(
            'Needle "%s" not found in %s',
            $needle,
            $fixtureRelativePath
        ));

        $position = PositionConverter::intByteOffsetToPosition($needlePosition + $needleOffset, $text);
        $response = $tester->requestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            $position,
        ));
        self::assertNotNull($response);

        return $response->result instanceof LspLocation ? $response->result : null;
    }

    private function sqlFileUri(string $fileName): string
    {
        return TextDocumentUri::fromString(
            $this->projectRoot . '/var/db/sql/' . $fileName
        )->__toString();
    }
}
