<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Resource;

use Suzumaze\BearPhpactor\Resource\Completor\ResourceUriCompletor;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Phpactor\Completion\Core\TypedCompletorRegistry;
use Phpactor\Extension\LanguageServerCodeTransform\Model\NameImport\NameImporter;
use Phpactor\Extension\LanguageServerCompletion\Handler\CompletionHandler;
use Phpactor\Extension\LanguageServerCompletion\Util\SuggestionNameFormatter;
use Phpactor\LanguageServer\LanguageServerTesterBuilder;
use Phpactor\LanguageServer\Test\LanguageServerTester;
use Phpactor\LanguageServer\Test\ProtocolFactory;
use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\CompletionList;
use Phpactor\LanguageServerProtocol\CompletionParams;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;
use PHPUnit\Framework\TestCase;

/**
 * 受け入れテスト: uri('app://self/<caret>') の位置で補完を要求すると、
 * 対象プロジェクトに実在するリソースクラスからURI候補が返る。
 */
final class ResourceUriCompletorTest extends TestCase
{
    private static function fixtureDir(): string
    {
        return dirname(__DIR__, 2) . '/Fixture/Resource';
    }

    public function testCompletesAppResourceUris(): void
    {
        $items = $this->requestCompletion("uri('app://self/'");

        $labels = array_map(fn (CompletionItem $item): string => $item->label, $items);
        self::assertContains('app://self/blog/posts', $labels);
        self::assertContains('app://self/user', $labels);
        // 別名インポート経由の間接継承も候補に出る (PLAN.md §2.17)
        self::assertContains('app://self/indirectAlias', $labels);
        // ResourceObject を継承しないクラスは候補に出ない
        self::assertNotContains('app://self/notAResource', $labels);
        // 独自の基底クラス (MyResourceObject) を継承するクラスは候補に出ない
        self::assertNotContains('app://self/extendsCustomBase', $labels);
        // docblock に 'extends ResourceObject' と書いてあるだけのクラスは候補に出ない
        self::assertNotContains('app://self/docblockMention', $labels);
        // 辿っても ResourceObject に行き着かないクラスは候補に出ない
        self::assertNotContains('app://self/indirectNotResource', $labels);
    }

    public function testFiltersByPartialUri(): void
    {
        $items = $this->requestCompletion("uri('app://self/u'");

        $labels = array_map(fn (CompletionItem $item): string => $item->label, $items);
        self::assertSame(['app://self/user'], $labels);
    }

    public function testCompletesPageResourceUris(): void
    {
        $items = $this->requestCompletion("uri('page://self/'");

        $labels = array_map(fn (CompletionItem $item): string => $item->label, $items);
        self::assertContains('page://self/index', $labels);
    }

    public function testNoCompletionOutsideString(): void
    {
        $items = $this->requestCompletion('function uri(');

        self::assertSame([], $items);
    }

    public function testSuggestionInsertsOnlyTheRemainderAndSortsFirst(): void
    {
        [$tester, $clientUri, $content] = $this->createTester();

        // uri('app://self/'); の行 (文字列の直後に ' が続くのはこの行だけ) にカーソルを置く
        $needle = "uri('app://self/'";
        $needleStart = strpos($content, $needle);
        self::assertNotFalse($needleStart);
        [$line, $char] = $this->offsetToPosition($needleStart + strlen("uri('app://self/"), $content);
        [$startLine, $startChar] = $this->offsetToPosition($needleStart + strlen("uri('"), $content);

        $response = $tester->requestAndWait('textDocument/completion', [
            'textDocument' => new TextDocumentIdentifier($clientUri),
            'position' => ProtocolFactory::position($line, $char),
        ]);
        self::assertNotNull($response);
        $tester->assertSuccess($response);

        $list = $response->result;
        self::assertInstanceOf(CompletionList::class, $list);
        $user = null;
        foreach ($list->items as $item) {
            if ($item->label === 'app://self/user') {
                $user = $item;
            }
        }
        self::assertNotNull($user);

        // phpactor は textEdit を出さない (上のコンストラクタのコメント参照)。
        // したがってエディタは insertText をカーソル位置にそのまま挿入する。
        self::assertNull($user->textEdit);

        // 挿入されるのは「まだ打っていない残り」だけ。完全なURIを入れると
        // 'app://self/app://self/user' になる (実機で確認した不具合)。
        self::assertSame('user', $user->insertText);

        // 並び順。sortText が無いと VS Code 任せになり、無関係な候補が上に来る。
        self::assertNotNull($user->sortText);
    }

    /**
     * 打ちかけのどこから補完しても、確定後の文字列が同じになること。
     *
     * phpactor は textEdit を出さないので、エディタは「カーソル位置の単語」を
     * 候補で置き換える。PHP の単語は [A-Za-z0-9_] なので 'app://self/body' の
     * 単語は 'body'。カーソルより後ろだけ ('TypeDemo') を渡すと
     * 'app://self/TypeDemo' になってしまう。実機で見つかった不具合。
     *
     * カーソル位置を厳密に置きたいので、LSP を通さずコンプリーターを直接呼ぶ。
     */
    public function testInsertTextRebuildsTheWholeUriWhereverTypingStopped(): void
    {
        $cases = [
            'app' => 'app://self/user',
            'app://' => 'self/user',
            'app://self' => 'self/user',
            'app://self/' => 'user',
            'app://self/us' => 'user',
        ];

        foreach ($cases as $partial => $expectedInsert) {
            $insert = $this->insertTextFor($partial);
            self::assertSame($expectedInsert, $insert, sprintf('"%s" まで打ったとき', $partial));

            // エディタの単語置き換えを再現し、確定後が完全なURIになることを確かめる
            $wordStart = strlen(rtrim($partial, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_'));
            self::assertSame(
                'app://self/user',
                substr($partial, 0, $wordStart) . $insert,
                sprintf('"%s" まで打って確定した結果', $partial),
            );
        }
    }

    /**
     * 打ちかけの文字列をフィクスチャに置き、その末尾にカーソルを置いて
     * app://self/user の候補の挿入テキストを返す。
     */
    private function insertTextFor(string $partial): ?string
    {
        $fixture = self::fixtureDir() . '/src/Client.php';
        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace Acme\\Blog;',
            '',
            'final class Typing',
            '{',
            '    public function run(): void',
            '    {',
            sprintf('        $this->resource->get(\'%s\');', $partial),
            '    }',
            '}',
            '',
        ];
        $content = implode("\n", $lines);
        $offset = strpos($content, $partial . "');");
        self::assertNotFalse($offset);

        $completor = new ResourceUriCompletor(new StringLiteralAtOffset());
        $suggestions = iterator_to_array($completor->complete(
            TextDocumentBuilder::create($content)->language('php')->uri('file://' . $fixture)->build(),
            ByteOffset::fromInt($offset + strlen($partial)),
        ));

        foreach ($suggestions as $suggestion) {
            if ($suggestion->label() === 'app://self/user') {
                return $suggestion->name();
            }
        }

        return null;
    }

    /**
     * @return list<CompletionItem>
     */
    private function requestCompletion(string $needle): array
    {
        [$tester, $clientUri, $content] = $this->createTester();
        // カーソルは needle の末尾 (閉じクォート位置 = 文字列の内側) に置く。
        // 閉じクォートの1バイト外は発火しない (StringLiteralAtOffset の境界修正)。
        [$line, $char] = $this->positionOf($needle, $content, strlen($needle) - 1);

        $response = $tester->requestAndWait('textDocument/completion', [
            'textDocument' => new TextDocumentIdentifier($clientUri),
            'position' => ProtocolFactory::position($line, $char),
        ]);
        self::assertNotNull($response);
        $tester->assertSuccess($response);

        $list = $response->result;
        self::assertInstanceOf(CompletionList::class, $list);

        return $list->items;
    }

    /**
     * @return array{LanguageServerTester, string, string}
     */
    private function createTester(): array
    {
        $clientPath = self::fixtureDir() . '/src/Client.php';
        $clientUri = 'file://' . $clientPath;
        $content = (string) file_get_contents($clientPath);

        $nameImporter = $this->getMockBuilder(NameImporter::class)
            ->disableOriginalConstructor()
            ->getMock();

        $builder = LanguageServerTesterBuilder::create();
        $tester = $builder->addHandler(new CompletionHandler(
            $builder->workspace(),
            new TypedCompletorRegistry([
                'php' => new ResourceUriCompletor(new StringLiteralAtOffset()),
            ]),
            new SuggestionNameFormatter(true),
            $nameImporter,
            true,
            // 第6引数 ($provideTextEdit) は渡さない。本番の
            // LanguageServerCompletionExtension が5引数でしか組み立てないため
            // (LanguageServerCompletionExtension.php:44-50)、常に false になる。
            // ここで true を渡していたせいで、本番では効かない textEdit を
            // 前提にしたテストが緑のままだった。
        ))->build();
        $tester->textDocument()->open($clientUri, $content);

        return [$tester, $clientUri, $content];
    }

    /**
     * バイトオフセットの位置を [行, 列] (0始まり) で返す。
     *
     * @return array{0: int, 1: int}
     */
    private function offsetToPosition(int $byteOffset, string $content): array
    {
        $before = substr($content, 0, $byteOffset);
        $lastNewline = strrpos($before, "\n");

        return [
            substr_count($before, "\n"),
            $lastNewline === false ? $byteOffset : $byteOffset - $lastNewline - 1,
        ];
    }

    /**
     * テキスト中の needle の先頭から charOffset 進めた位置を [行, 列] (0始まり) で返す。
     *
     * @return array{0: int, 1: int}
     */
    private function positionOf(string $needle, string $content, int $charOffset = 0): array
    {
        $byteOffset = strpos($content, $needle);
        self::assertNotFalse($byteOffset, sprintf('Needle "%s" not found in fixture', $needle));

        return $this->offsetToPosition($byteOffset + $charOffset, $content);
    }
}
