<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Resource;

use Suzumaze\BearPhpactor\Resource\Completor\BodyPropertyCompletor;
use Phpactor\Completion\Core\Suggestion;
use Phpactor\Completion\Core\TypedCompletorRegistry;
use Phpactor\Extension\LanguageServerCodeTransform\Model\NameImport\NameImporter;
use Phpactor\Extension\LanguageServerCompletion\Handler\CompletionHandler;
use Phpactor\Extension\LanguageServerCompletion\Util\SuggestionNameFormatter;
use Phpactor\LanguageServer\LanguageServerTesterBuilder;
use Phpactor\LanguageServer\Test\ProtocolFactory;
use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\CompletionList;
use Phpactor\LanguageServerProtocol\CompletionParams;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;
use PHPUnit\Framework\TestCase;

/**
 * 受け入れテスト: リソースクラス内の $this->body['<caret>'] で補完を要求すると、
 * そのリソースの JSON Schema の properties キーが候補として返る。
 */
final class BodyPropertyCompletorTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixture/Body/basic';

    public function testCompletesSchemaPropertiesFromConvention(): void
    {
        $items = $this->requestCompletion('src/Resource/App/User.php', '<caret-1>');

        $labels = array_map(fn (CompletionItem $item): string => $item->label, $items);
        self::assertContains('id', $labels);
        self::assertContains('name', $labels);
        self::assertContains('email', $labels);
    }

    public function testFiltersByPartialKey(): void
    {
        $items = $this->requestCompletion('src/Resource/App/User.php', '<caret-2>');

        $labels = array_map(fn (CompletionItem $item): string => $item->label, $items);
        self::assertSame(['name'], $labels);
    }

    public function testSuggestionCarriesRequiredFlag(): void
    {
        $suggestions = $this->suggestions('src/Resource/App/User.php', '<caret-1>');

        $required = null;
        $optional = null;
        foreach ($suggestions as $suggestion) {
            self::assertInstanceOf(Suggestion::class, $suggestion);
            if ($suggestion->name() === 'id') {
                $required = $suggestion;
            }
            if ($suggestion->name() === 'email') {
                $optional = $suggestion;
            }
        }
        self::assertNotNull($required);
        self::assertNotNull($optional);
        self::assertSame('required', $required->shortDescription());
        self::assertSame('optional', $optional->shortDescription());
    }

    public function testCompletesFromMethodAttribute(): void
    {
        $items = $this->requestCompletion('src/Resource/App/Profile.php', '<caret-1>');

        $labels = array_map(fn (CompletionItem $item): string => $item->label, $items);
        self::assertContains('nickname', $labels);
        self::assertContains('bio', $labels);
    }

    public function testCompletesFromNamedSchemaArgument(): void
    {
        $items = $this->requestCompletion('src/Resource/App/Profile.php', '<caret-3>');

        $labels = array_map(fn (CompletionItem $item): string => $item->label, $items);
        self::assertContains('nickname', $labels);
        self::assertContains('bio', $labels);
    }

    public function testParamsAttributeFallsBackToConvention(): void
    {
        $items = $this->requestCompletion('src/Resource/App/Profile.php', '<caret-2>');

        $labels = array_map(fn (CompletionItem $item): string => $item->label, $items);
        // params: はリクエストスキーマなので候補に出ない (profile-params.json の token は含まれない)
        self::assertContains('nickname', $labels);
        self::assertContains('bio', $labels);
        self::assertNotContains('token', $labels);
    }

    public function testNoCompletionWhenSchemaMissing(): void
    {
        $items = $this->requestCompletion('src/Resource/App/NoSchema.php', '<caret>');

        self::assertSame([], $items);
    }

    public function testNoCompletionWhenSchemaBroken(): void
    {
        $items = $this->requestCompletion('src/Resource/App/Broken.php', '<caret>');

        self::assertSame([], $items);
    }

    public function testNoCompletionForOtherBody(): void
    {
        $items = $this->requestCompletion('src/Resource/App/OtherBody.php', '<caret>');

        self::assertSame([], $items);
    }

    public function testNoCompletionForOtherBodyWithinResourceClass(): void
    {
        $items = $this->requestCompletion('src/Resource/App/User.php', '<caret-3>');

        self::assertSame([], $items);
    }

    public function testNoCompletionInNonResourceClass(): void
    {
        $items = $this->requestCompletion('src/Service/Helper.php', '<caret>');

        self::assertSame([], $items);
    }

    public function testNoCompletionOneBytePastClosingQuote(): void
    {
        // 閉じクォートの1バイト外では発火しない。クラス名は user.json が実在する
        // User に合わせ、境界判定が無ければ候補が出てしまう構成にする。
        $text = "<?php\n\n"
            . "namespace MyVendor\\BodyFixture\\Resource\\App;\n\n"
            . "final class User extends ResourceObject\n"
            . "{\n"
            . "    public function onGet(): static\n"
            . "    {\n"
            . "        \$this->body['id'];\n"
            . "    }\n"
            . "}\n";
        $caretOffset = strpos($text, "'id']") + strlen("'id'");
        self::assertNotFalse($caretOffset);

        $items = $this->requestCompletionAt($text, $caretOffset, 'src/Resource/App/inline.php');

        self::assertSame([], $items);
    }

    public function testNoCompletionOnOpeningQuote(): void
    {
        // 開きクォート上 (文字列の外側) では発火しない。
        $text = "<?php\n\n"
            . "namespace MyVendor\\BodyFixture\\Resource\\App;\n\n"
            . "final class User extends ResourceObject\n"
            . "{\n"
            . "    public function onGet(): static\n"
            . "    {\n"
            . "        \$this->body['id'];\n"
            . "    }\n"
            . "}\n";
        $caretOffset = strpos($text, "'id'");
        self::assertNotFalse($caretOffset);

        $items = $this->requestCompletionAt($text, $caretOffset, 'src/Resource/App/inline-opening.php');

        self::assertSame([], $items);
    }

    public function testAttributeGroupBrokenFallsBackToConvention(): void
    {
        // 壊れた属性グループ (#[ で途切れ) があっても例外にせず、
        // 属性が見つからないので規約 (user.json) にフォールバックする。
        $text = "<?php\n\n"
            . "namespace MyVendor\\BodyFixture\\Resource\\App;\n\n"
            . "final class User extends ResourceObject\n"
            . "{\n"
            . "    #[\n"
            . "    public function onGet(): static\n"
            . "    {\n"
            . "        \$this->body[''];\n"
            . "    }\n"
            . "}\n";
        $caretOffset = strpos($text, "['']") + 2;
        self::assertNotFalse($caretOffset);

        $items = $this->requestCompletionAt($text, $caretOffset, 'src/Resource/App/inline-broken.php');

        $labels = array_map(fn (CompletionItem $item): string => $item->label, $items);
        self::assertContains('id', $labels);
    }

    public function testSuggestionCarriesTextEditReplacingPartial(): void
    {
        [$text, $caretOffset] = $this->fixtureWithCaret('src/Resource/App/User.php', '<caret-2>');
        $items = $this->requestCompletionAt($text, $caretOffset, 'src/Resource/App/User.php');

        $name = null;
        foreach ($items as $item) {
            if ($item->label === 'name') {
                $name = $item;
            }
        }
        self::assertNotNull($name);

        // 文字列コンテンツ先頭 (開きクォート直後) からカーソルまでを 'name' で置き換える
        $contentStart = strpos($text, "['na") + 2;
        [$startLine, $startChar] = $this->offsetToPosition($contentStart, $text);
        [$line, $char] = $this->offsetToPosition($caretOffset, $text);

        self::assertNotNull($name->textEdit);
        self::assertSame($startLine, $name->textEdit->range->start->line);
        self::assertSame($startChar, $name->textEdit->range->start->character);
        self::assertSame($line, $name->textEdit->range->end->line);
        self::assertSame($char, $name->textEdit->range->end->character);
        self::assertSame('name', $name->textEdit->newText);
    }

    /**
     * フィクスチャのマーカー位置で LSP の補完を要求し、候補アイテムを返す。
     *
     * @return list<CompletionItem>
     */
    private function requestCompletion(string $relativePath, string $marker): array
    {
        [$text, $caretOffset] = $this->fixtureWithCaret($relativePath, $marker);

        return $this->requestCompletionAt($text, $caretOffset, $relativePath);
    }

    /**
     * @return list<CompletionItem>
     */
    private function requestCompletionAt(string $text, int $caretOffset, string $docRelativePath): array
    {
        $uri = 'file://' . self::FIXTURE . '/' . $docRelativePath;

        $nameImporter = $this->getMockBuilder(NameImporter::class)
            ->disableOriginalConstructor()
            ->getMock();

        $builder = LanguageServerTesterBuilder::create();
        $tester = $builder->addHandler(new CompletionHandler(
            $builder->workspace(),
            new TypedCompletorRegistry([
                'php' => new BodyPropertyCompletor(),
            ]),
            new SuggestionNameFormatter(true),
            $nameImporter,
            true,
            true,
        ))->build();
        $tester->textDocument()->open($uri, $text);

        [$line, $char] = $this->offsetToPosition($caretOffset, $text);
        $response = $tester->requestAndWait('textDocument/completion', [
            'textDocument' => new TextDocumentIdentifier($uri),
            'position' => ProtocolFactory::position($line, $char),
        ]);
        self::assertNotNull($response);
        $tester->assertSuccess($response);

        $list = $response->result;
        self::assertInstanceOf(CompletionList::class, $list);

        return $list->items;
    }

    /**
     * コンプリーターを直接呼んで候補の Suggestion を返す。
     * 説明文 (required/optional) は LSP の completionItem/resolve をまたずに
     * 確認できる。
     *
     * @return array<int, Suggestion>
     */
    private function suggestions(string $relativePath, string $marker): array
    {
        [$text, $caretOffset] = $this->fixtureWithCaret($relativePath, $marker);
        $uri = 'file://' . self::FIXTURE . '/' . $relativePath;

        $completor = new BodyPropertyCompletor();

        return iterator_to_array($completor->complete(
            TextDocumentBuilder::create($text)->language('php')->uri($uri)->build(),
            ByteOffset::fromInt($caretOffset),
        ));
    }

    /**
     * フィクスチャから <caret> マーカーを取り除いたテキストと、そのマーカー位置の
     * バイトオフセットを返す。複数マーカーは長いものから消す（<caret> が <caret-1>
     * の先頭に一致しないように）。
     *
     * @return array{0: string, 1: int}
     */
    private function fixtureWithCaret(string $relativePath, string $marker): array
    {
        $source = file_get_contents(self::FIXTURE . '/' . $relativePath);
        self::assertNotFalse($source);

        $markerPosition = strpos($source, $marker);
        self::assertNotFalse($markerPosition, sprintf('Marker "%s" not found in %s', $marker, $relativePath));

        $markers = ['<caret-3>', '<caret-2>', '<caret-1>', '<caret>'];
        $caretOffset = strlen(str_replace($markers, '', substr($source, 0, $markerPosition)));
        $text = str_replace($markers, '', $source);

        return [$text, $caretOffset];
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
}
