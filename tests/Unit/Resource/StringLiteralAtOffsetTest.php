<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Resource;

use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Phpactor\TextDocument\TextDocumentBuilder;
use PHPUnit\Framework\TestCase;

/**
 * カーソル位置の文字列リテラル抽出が「文字列の内側」だけで発火することの検証。
 *
 * getDescendantNodeAtPosition() はノードの終端位置を含むため、境界を補正しないと
 * 閉じクォートの1バイト外でも発火してしまう。4機能 (Resource / SQL / Router /
 * JsonSchema) はすべてこの部品に寄せており、ここでの境界が各機能の境界になる。
 */
final class StringLiteralAtOffsetTest extends TestCase
{
    private const SOURCE = "<?php\n\$x = uri('app://self/user');\n";

    private StringLiteralAtOffset $stringLiteralAtOffset;

    protected function setUp(): void
    {
        $this->stringLiteralAtOffset = new StringLiteralAtOffset();
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    private function invoke(int $byteOffset): ?array
    {
        $document = TextDocumentBuilder::create(self::SOURCE)->language('php')->build();

        return ($this->stringLiteralAtOffset)($document, $byteOffset);
    }

    public function testFiresInsideString(): void
    {
        $start = $this->contentsStart();
        $result = $this->invoke($start + 3);

        self::assertSame([$start, 'app://self/user'], $result);
    }

    public function testFiresOnOpeningQuote(): void
    {
        $start = $this->contentsStart();
        $result = $this->invoke($start - 1);

        self::assertSame([$start, 'app://self/user'], $result);
    }

    public function testFiresOnClosingQuote(): void
    {
        $start = $this->contentsStart();
        $result = $this->invoke($start + strlen('app://self/user'));

        self::assertSame([$start, 'app://self/user'], $result);
    }

    public function testDoesNotFireOneBytePastClosingQuote(): void
    {
        $start = $this->contentsStart();
        $result = $this->invoke($start + strlen('app://self/user') + 1);

        self::assertNull($result);
    }

    public function testDoesNotFireBeforeOpeningQuote(): void
    {
        $start = $this->contentsStart();
        $result = $this->invoke($start - 2);

        self::assertNull($result);
    }

    public function testDoesNotFireOutsideStringLiteral(): void
    {
        $result = $this->invoke(strpos(self::SOURCE, '$x'));

        self::assertNull($result);
    }

    public function testLiteralReturnsNodeInsideString(): void
    {
        $document = TextDocumentBuilder::create(self::SOURCE)->language('php')->build();
        $literal = $this->stringLiteralAtOffset->literal($document, $this->contentsStart() + 1);

        self::assertNotNull($literal);
        self::assertSame('app://self/user', $literal->getStringContentsText());
    }

    private function contentsStart(): int
    {
        $quote = strpos(self::SOURCE, "'app://self/user'");
        self::assertNotFalse($quote);

        return $quote + 1;
    }
}
