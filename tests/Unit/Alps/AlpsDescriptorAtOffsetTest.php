<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Alps;

use Phpactor\TextDocument\TextDocumentBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Alps\AlpsDescriptorAtOffset;

final class AlpsDescriptorAtOffsetTest extends TestCase
{
    /**
     * @return iterable<string,array{string,string}>
     */
    public static function supportedAttributeNames(): iterable
    {
        yield 'imported short name' => ['use BEAR\\ApiDoc\\Annotation\\Alps;', 'Alps'];
        yield 'import alias' => ['use BEAR\\ApiDoc\\Annotation\\Alps as Semantic;', 'Semantic'];
        yield 'explicit name without leading backslash' => ['', 'BEAR\\ApiDoc\\Annotation\\Alps'];
        yield 'fully qualified name' => ['', '\\BEAR\\ApiDoc\\Annotation\\Alps'];
    }

    #[DataProvider('supportedAttributeNames')]
    public function testLocatesOnlyFirstArgumentOfSupportedAttribute(string $import, string $attributeName): void
    {
        $source = sprintf("<?php\n%s\n#[%s('goArticle', 'secondValue')]\n", $import, $attributeName);
        $contentStart = strpos($source, 'goArticle');
        self::assertNotFalse($contentStart);

        self::assertSame(
            [$contentStart, 'goArticle', $contentStart + strlen('goArticle')],
            $this->locate($source, 'goArticle'),
        );
        self::assertNull($this->locate($source, 'secondValue'));
    }

    public function testRejectsAlpsShortNameResolvedToAnotherNamespace(): void
    {
        $source = "<?php\nnamespace App;\nuse Other\\Alps;\n#[Alps('goArticle')]\n";

        self::assertNull($this->locate($source, 'goArticle'));
    }

    public function testRejectsOpeningAndClosingQuotePositions(): void
    {
        $source = "<?php\nuse BEAR\\ApiDoc\\Annotation\\Alps;\n#[Alps('goArticle')]\n";
        $contentStart = strpos($source, 'goArticle');
        self::assertNotFalse($contentStart);
        $document = TextDocumentBuilder::create($source)->language('php')->build();
        $locator = new AlpsDescriptorAtOffset();

        self::assertNull($locator($document, $contentStart - 1));
        self::assertNull($locator($document, $contentStart + strlen('goArticle')));
    }

    public function testListsOnlySupportedStaticDescriptorReferencesInSourceOrder(): void
    {
        $source = <<<'PHP'
<?php
use BEAR\ApiDoc\Annotation\Alps as Semantic;
#[Semantic('firstDescriptor')]
final class First {}
#[\BEAR\ApiDoc\Annotation\Alps('secondDescriptor')]
final class Second {}
#[Semantic('valid', 'notDescriptor')]
final class ExtraArgument {}
#[\Other\Alps('foreignDescriptor')]
final class Foreign {}
#[Semantic(self::DESCRIPTOR)]
final class Dynamic {}
PHP;
        $document = TextDocumentBuilder::create($source)->language('php')->build();

        self::assertSame(
            ['firstDescriptor', 'secondDescriptor', 'valid'],
            array_column((new AlpsDescriptorAtOffset())->references($document), 1),
        );
    }

    /** @return array{int,string,int}|null */
    private function locate(string $source, string $needle): ?array
    {
        $offset = strpos($source, $needle);
        self::assertNotFalse($offset);
        $document = TextDocumentBuilder::create($source)->language('php')->build();

        return (new AlpsDescriptorAtOffset())($document, $offset);
    }
}
