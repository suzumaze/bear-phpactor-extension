<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\JsonSchema;

use Phpactor\TextDocument\TextDocumentBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaReferenceAtOffset;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaQuery;

final class JsonSchemaReferenceAtOffsetTest extends TestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function supportedAttributeNames(): iterable
    {
        yield 'imported short name' => ['use BEAR\Resource\Annotation\JsonSchema;', 'JsonSchema'];
        yield 'import alias' => ['use BEAR\Resource\Annotation\JsonSchema as Schema;', 'Schema'];
        yield 'explicit name without leading backslash' => ['', 'BEAR\Resource\Annotation\JsonSchema'];
        yield 'fully qualified name' => ['', '\BEAR\Resource\Annotation\JsonSchema'];
    }

    #[DataProvider('supportedAttributeNames')]
    public function testLocatesFirstPositionalResponseSchema(string $import, string $attributeName): void
    {
        $source = sprintf("<?php\n%s\n#[%s('user.json')]\n", $import, $attributeName);

        self::assertSame(
            $this->expected($source, 'user.json', SchemaQuery::KIND_RESPONSE),
            $this->locate($source, 'user.json'),
        );
    }

    public function testLocatesSchemaAndParamsNamedArgumentsIndependently(): void
    {
        $source = "<?php\nuse BEAR\Resource\Annotation\JsonSchema;\n"
            . "#[JsonSchema(schema: 'user.json', params: 'user-params.json')]\n";

        self::assertSame(
            $this->expected($source, 'user.json', SchemaQuery::KIND_RESPONSE),
            $this->locate($source, 'user.json'),
        );
        self::assertSame(
            $this->expected($source, 'user-params.json', SchemaQuery::KIND_REQUEST),
            $this->locate($source, 'user-params.json'),
        );
    }

    public function testRejectsNonSchemaArgumentsAndSecondPositionalArgument(): void
    {
        $source = "<?php\nuse BEAR\Resource\Annotation\JsonSchema;\n"
            . "#[JsonSchema('user.json', 'second.json', key: 'id', target: 'query')]\n";

        self::assertNull($this->locate($source, 'second.json'));
        self::assertNull($this->locate($source, 'id'));
        self::assertNull($this->locate($source, 'query'));
    }

    public function testRejectsShortNameResolvedToAnotherNamespace(): void
    {
        $source = "<?php\nnamespace App;\nuse Other\JsonSchema;\n#[JsonSchema('user.json')]\n";

        self::assertNull($this->locate($source, 'user.json'));
    }

    public function testRejectsStringLiteralInsideDynamicArgumentExpression(): void
    {
        $source = "<?php\nuse BEAR\Resource\Annotation\JsonSchema;\n"
            . "#[JsonSchema('user.' . \$extension)]\n";

        self::assertNull($this->locate($source, 'user.'));
    }

    public function testRejectsOpeningAndClosingQuotePositions(): void
    {
        $source = "<?php\nuse BEAR\Resource\Annotation\JsonSchema;\n#[JsonSchema('user.json')]\n";
        $contentStart = strpos($source, 'user.json');
        self::assertNotFalse($contentStart);
        $document = TextDocumentBuilder::create($source)->language('php')->build();
        $locator = new JsonSchemaReferenceAtOffset();

        self::assertNull($locator($document, $contentStart - 1));
        self::assertNull($locator($document, $contentStart + strlen('user.json')));
    }

    /** @return array{int,string,int,string} */
    private function expected(string $source, string $needle, string $kind): array
    {
        $offset = strpos($source, $needle);
        self::assertNotFalse($offset);

        return [$offset, $needle, $offset + strlen($needle), $kind];
    }

    /** @return array{int,string,int,string}|null */
    private function locate(string $source, string $needle): ?array
    {
        $offset = strpos($source, $needle);
        self::assertNotFalse($offset);
        $document = TextDocumentBuilder::create($source)->language('php')->build();

        return (new JsonSchemaReferenceAtOffset())($document, $offset);
    }
}
