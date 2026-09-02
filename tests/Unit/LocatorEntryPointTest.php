<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit;

use Suzumaze\BearPhpactor\Alps\AlpsDefinitionLocator;
use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaConventionTypeLocator;
use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaDefinitionLocator;
use Suzumaze\BearPhpactor\Resource\ReferenceFinder\ResourceDefinitionLocator;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Router\RouterDefinitionLocator;
use Suzumaze\BearPhpactor\Sql\SqlDefinitionLocator;
use Microsoft\PhpParser\Parser;
use Phpactor\ReferenceFinder\Exception\CouldNotLocateDefinition;
use Phpactor\ReferenceFinder\Exception\UnsupportedDocument;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;
use PHPUnit\Framework\TestCase;

/**
 * 5ロケータの入口の安価な事前判定。
 *
 * 自拡張が container.extension_classes の先頭に来ると、すべてのPHPファイルの
 * すべての定義ジャンプで最初に走る。該当しないことが安価に分かる場合は
 * 構文解析より先に降りることを、パーサーが呼ばれないこと（モック）で担保する。
 * 事前判定は必要条件の検査であり、誤検出（判定を通過して解析に進む）は許容、
 * 取りこぼし（該当するのに降りる）は禁止。
 */
final class LocatorEntryPointTest extends TestCase
{
    public function testSqlBailsBeforeParsingWithoutQueryReference(): void
    {
        $locator = new SqlDefinitionLocator(new StringLiteralAtOffset($this->parserThatMustNotRun()));
        $document = TextDocumentBuilder::create("<?php\nfinal class Foo\n{\n}\n")->language('php')->build();

        $this->expectException(CouldNotLocateDefinition::class);
        $locator->locateDefinition($document, ByteOffset::fromInt(10));
    }

    public function testJsonSchemaBailsBeforeParsingWithoutSchemaReference(): void
    {
        $locator = new JsonSchemaDefinitionLocator(
            new StringLiteralAtOffset($this->parserThatMustNotRun()),
            $this->parserThatMustNotRun(),
        );
        $document = TextDocumentBuilder::create("<?php\nfinal class Foo\n{\n}\n")->language('php')->build();

        $this->expectException(CouldNotLocateDefinition::class);
        $locator->locateDefinition($document, ByteOffset::fromInt(10));
    }

    public function testAlpsBailsBeforeParsingWithoutAlpsAttribute(): void
    {
        $locator = new AlpsDefinitionLocator(new StringLiteralAtOffset($this->parserThatMustNotRun()));
        $document = TextDocumentBuilder::create("<?php\nfinal class Foo\n{\n}\n")->language('php')->build();

        $this->expectException(CouldNotLocateDefinition::class);
        $locator->locateDefinition($document, ByteOffset::fromInt(10));
    }

    public function testJsonSchemaConventionTypeLocatorBailsBeforeParsingWithoutResourceNamespace(): void
    {
        // 型定義ジャンプ側の入口の事前判定。リソース名前空間 (Resource\App /
        // Resource\Page) が無ければ構文解析より先に降りる。定義ロケータと違い
        // 例外ではなく空を返す (ChainTypeLocator は CouldNotLocateType を捕まえ
        // ない。投げると組込みの型定義解決まで鎖が届かない)。
        $locator = new JsonSchemaConventionTypeLocator($this->parserThatMustNotRun());
        $document = TextDocumentBuilder::create("<?php\nfinal class Foo\n{\n}\n")->language('php')->build();

        $locations = $locator->locateTypes($document, ByteOffset::fromInt(10));
        self::assertSame(0, $locations->count());
    }

    public function testResourceBailsBeforeParsingWithoutResourceUri(): void
    {
        $locator = new ResourceDefinitionLocator(new StringLiteralAtOffset($this->parserThatMustNotRun()));
        $document = TextDocumentBuilder::create("<?php\n\$x = 'hello';\n")->language('php')->build();

        $this->expectException(CouldNotLocateDefinition::class);
        $locator->locateDefinition($document, ByteOffset::fromInt(10));
    }

    public function testRouterBailsBeforeParsingForNonRouteFile(): void
    {
        $locator = new RouterDefinitionLocator(
            $this->parserThatMustNotRun(),
            new StringLiteralAtOffset($this->parserThatMustNotRun()),
        );
        $document = TextDocumentBuilder::create("<?php\n\$map->get('index', '/index');\n")
            ->uri('file:///tmp/not-aura-route.php')
            ->language('php')
            ->build();

        $this->expectException(UnsupportedDocument::class);
        $locator->locateDefinition($document, ByteOffset::fromInt(20));
    }

    private function parserThatMustNotRun(): Parser
    {
        $parser = $this->createMock(Parser::class);
        $parser->expects(self::never())->method('parseSourceFile');

        return $parser;
    }
}
