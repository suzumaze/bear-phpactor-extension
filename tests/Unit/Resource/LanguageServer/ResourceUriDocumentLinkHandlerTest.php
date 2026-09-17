<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Resource\LanguageServer;

use Suzumaze\BearPhpactor\Resource\LanguageServer\ResourceUriDocumentLinkHandler;
use Suzumaze\BearPhpactor\Resource\ReferenceFinder\ResourceDefinitionLocator;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Tests\Unit\Semantic\Resource\Support\CountingParser;
use Microsoft\PhpParser\Parser;
use Phpactor\LanguageServer\LanguageServerTesterBuilder;
use Phpactor\LanguageServer\Test\ProtocolFactory;
use PHPUnit\Framework\TestCase;

use function file_get_contents;

/**
 * リソースURI全体が1つのリンクになることを固定する。
 *
 * 定義ジャンプ経由だとクリック範囲を指定できず、エディタが単語境界で切るので
 * 'app://self/user' が app / self / user の3つに割れる。documentLink なら
 * サーバーが範囲を明示できる。
 */
final class ResourceUriDocumentLinkHandlerTest extends TestCase
{
    public function testLinkCoversTheWholeUriNotItsWords(): void
    {
        [$tester, $uri, $content] = $this->createTester();

        $response = $tester->requestAndWait('textDocument/documentLink', [
            'textDocument' => ProtocolFactory::textDocumentIdentifier($uri),
        ]);
        self::assertNotNull($response);
        $tester->assertSuccess($response);

        $links = $response->result;
        self::assertIsArray($links);
        self::assertNotSame([], $links, 'リンクが1件も無い');

        $lines = explode("\n", $content);
        foreach ($links as $link) {
            $line = $lines[$link->range->start->line];
            $text = substr(
                $line,
                $link->range->start->character,
                $link->range->end->character - $link->range->start->character,
            );

            // 範囲がURI全体であること。'app' や 'user' のような断片ではない。
            // ホストは self とは限らない (ImportApp の app://tags/ など)。
            self::assertMatchesRegularExpression('#^(app|page)://[a-z0-9_-]+/\S+$#', $text);
            self::assertNotNull($link->target);
        }
    }

    public function testUnresolvableUriGetsNoLink(): void
    {
        [$tester, $uri, $content] = $this->createTester();

        $response = $tester->requestAndWait('textDocument/documentLink', [
            'textDocument' => ProtocolFactory::textDocumentIdentifier($uri),
        ]);
        self::assertNotNull($response);

        $lines = explode("\n", $content);
        $linked = [];
        foreach ((array) $response->result as $link) {
            $line = $lines[$link->range->start->line];
            $linked[] = substr(
                $line,
                $link->range->start->character,
                $link->range->end->character - $link->range->start->character,
            );
        }

        // 飛び先の無いURIに下線を出すのは嘘になる。
        self::assertNotContains('app://self/doesNotExistAnywhere', $linked);
    }

    public function testParsesDocumentOnlyOnceForAllResourceLinks(): void
    {
        $parser = new CountingParser();
        [$tester, $uri] = $this->createTester($parser);

        $response = $tester->requestAndWait('textDocument/documentLink', [
            'textDocument' => ProtocolFactory::textDocumentIdentifier($uri),
        ]);

        self::assertNotNull($response);
        $tester->assertSuccess($response);
        self::assertSame(1, $parser->parseCount);
    }

    /** @return array{0: \Phpactor\LanguageServer\Test\LanguageServerTester, 1: string, 2: string} */
    private function createTester(?Parser $parser = null): array
    {
        $fixture = dirname(__DIR__, 3) . '/Fixture/Resource';
        $path = $fixture . '/src/Client.php';
        $content = (string) file_get_contents($path);
        $uri = 'file://' . $path;

        $builder = LanguageServerTesterBuilder::createBare()->enableTextDocuments();
        $parser ??= new Parser();

        // 本番の BearSundayExtension と同じく、実物のロケータを渡して組み立てる。
        // 別物を渡すと、本番に無い構成でテストが緑になる (補完で一度やった)。
        $tester = $builder->addHandler(new ResourceUriDocumentLinkHandler(
            $builder->workspace(),
            new ResourceDefinitionLocator(new StringLiteralAtOffset($parser)),
            parser: $parser,
        ))->build();

        $tester->textDocument()->open($uri, $content);

        return [$tester, $uri, $content];
    }
}
