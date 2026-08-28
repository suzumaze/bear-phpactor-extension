<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Resource;

use Suzumaze\BearPhpactor\Resource\Completor\BodyPropertyCompletor;
use Microsoft\PhpParser\Parser;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;
use PHPUnit\Framework\TestCase;

/**
 * 補完連鎖の先頭にいる BodyPropertyCompletor の入口の安価な事前判定。
 *
 * 該当しない文書では構文解析より先に降りることを、パーサーが呼ばれないこと
 * (モック) で担保する。事前判定は必要条件の検査であり、誤検出 (判定を通過して
 * 解析に進む) は許容、取りこぼし (該当するのに降りる) は禁止。
 */
final class BodyPropertyCompletorEntryPointTest extends TestCase
{
    public function testBailsBeforeParsingWithoutBodyReference(): void
    {
        $completor = new BodyPropertyCompletor($this->parserThatMustNotRun());
        $document = TextDocumentBuilder::create("<?php\n\$x = 'hello';\n")->language('php')->build();

        $items = iterator_to_array($completor->complete($document, ByteOffset::fromInt(10)));

        self::assertSame([], $items);
    }

    public function testBailsBeforeParsingForOtherBodyReference(): void
    {
        $completor = new BodyPropertyCompletor($this->parserThatMustNotRun());
        $document = TextDocumentBuilder::create("<?php\n\$other->body['x'];\n")->language('php')->build();

        $items = iterator_to_array($completor->complete($document, ByteOffset::fromInt(15)));

        self::assertSame([], $items);
    }

    public function testBailsBeforeParsingWithoutDocumentUri(): void
    {
        // $this->body を含むが URI が無い文書 → ルート探索もパースもせず降りる
        $completor = new BodyPropertyCompletor($this->parserThatMustNotRun());
        $document = TextDocumentBuilder::create("<?php\n\$this->body['x'];\n")->language('php')->build();

        $items = iterator_to_array($completor->complete($document, ByteOffset::fromInt(10)));

        self::assertSame([], $items);
    }

    public function testReturnsEmptyWhenCursorIsNotOnBodySubscript(): void
    {
        // $this->body を含むリソースファイルでも、カーソルが添字の外なら候補なし
        $path = __DIR__ . '/../../Fixture/Body/basic/src/Resource/App/User.php';
        $source = (string) file_get_contents($path);

        $completor = new BodyPropertyCompletor();
        $document = TextDocumentBuilder::create($source)
            ->language('php')
            ->uri('file://' . $path)
            ->build();

        $items = iterator_to_array(
            $completor->complete($document, ByteOffset::fromInt(strpos($source, 'final class')))
        );

        self::assertSame([], $items);
    }

    private function parserThatMustNotRun(): Parser
    {
        $parser = $this->createMock(Parser::class);
        $parser->expects(self::never())->method('parseSourceFile');

        return $parser;
    }
}
