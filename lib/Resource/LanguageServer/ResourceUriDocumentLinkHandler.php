<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Resource\LanguageServer;

use Amp\Promise;
use Suzumaze\BearPhpactor\Resource\ReferenceFinder\ResourceDefinitionLocator;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Parser;
use Phpactor\Extension\LanguageServerBridge\Converter\PositionConverter;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServer\Core\Workspace\Workspace;
use Phpactor\LanguageServerProtocol\DocumentLink;
use Phpactor\LanguageServerProtocol\DocumentLinkOptions;
use Phpactor\LanguageServerProtocol\DocumentLinkParams;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\ReferenceFinder\Exception\CouldNotLocateDefinition;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;

use function Amp\call;
use function iterator_to_array;
use function str_contains;

/**
 * リソースURI全体を1つのリンクにする。
 *
 * 定義ジャンプ (textDocument/definition) は飛び先しか返せない。phpactor の
 * GotoDefinitionHandler は Location だけを返し、LocationLink も
 * originSelectionRange も扱わないため、クリック範囲はエディタが
 * 「カーソル位置の単語」から決める。その結果 'app://self/user' が
 * app / self / user の3つの別々のリンクとして下線表示されてしまう。
 * どれを押しても同じクラスに飛ぶので、「別々の場所へ行ける」という嘘の予告になる。
 *
 * textDocument/documentLink はサーバーがクリック範囲を明示できる。
 * phpactor はこのメソッドを実装していないので、登録しても何も置き換えない。
 *
 * 飛び先の解決は ResourceDefinitionLocator をそのまま呼ぶ。別に書くと
 * リンクとジャンプで挙動がずれる (同じ処理の重複が実バグになった経緯は PLAN.md §2.6)。
 * 曖昧なURIでロケータが諦めればリンクも出ない、という一致も自動的に得られる。
 */
final class ResourceUriDocumentLinkHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private Workspace $workspace,
        private ResourceDefinitionLocator $locator,
        private Parser $parser = new Parser(),
    ) {
    }

    /** @return array<string,string> */
    public function methods(): array
    {
        return ['textDocument/documentLink' => 'documentLink'];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        // 解決済みの target を最初から返すので resolveProvider は false。
        $capabilities->documentLinkProvider = new DocumentLinkOptions(false);
    }

    /** @return Promise<list<DocumentLink>> */
    public function documentLink(DocumentLinkParams $params): Promise
    {
        return call(function () use ($params): array {
            $textDocument = $this->workspace->get($params->textDocument->uri);
            $text = $textDocument->text;

            // 入口の安価な事前判定。どちらのスキームも無ければ解析せず降りる。
            if (!str_contains($text, 'app://') && !str_contains($text, 'page://')) {
                return [];
            }

            $document = TextDocumentBuilder::create($text)
                ->uri($textDocument->uri)
                ->language($textDocument->languageId)
                ->build();

            $links = [];
            foreach ($this->uriRanges($text) as [$start, $end]) {
                try {
                    $locations = $this->locator->locateDefinition($document, ByteOffset::fromInt($start));
                } catch (CouldNotLocateDefinition) {
                    // 解決できないURIにリンクは出さない。飛び先の無い下線は嘘になる。
                    continue;
                }

                // TypeLocations は Countable ではないので配列にしてから数える。
                // 曖昧なURI (候補が複数) にはリンクを出さない。定義ジャンプ側と揃える。
                if (count(iterator_to_array($locations)) !== 1) {
                    continue;
                }

                $links[] = new DocumentLink(
                    new Range(
                        PositionConverter::byteOffsetToPosition(ByteOffset::fromInt($start), $text),
                        PositionConverter::byteOffsetToPosition(ByteOffset::fromInt($end), $text),
                    ),
                    $locations->first()->location()->uri()->__toString(),
                );
            }

            return $links;
        });
    }

    /**
     * リソースURIである文字列リテラルの、クォートの内側の範囲を返す。
     *
     * 正規表現ではなく構文解析で拾う。コメントに書かれた 'app://self/user' に
     * リンクを出してしまうため (同じ誤りを tools/coverage.php で一度やった)。
     *
     * @return list<array{0:int,1:int}>
     */
    private function uriRanges(string $text): array
    {
        $ranges = [];
        foreach ($this->parser->parseSourceFile($text)->getDescendantNodes() as $node) {
            if (!$node instanceof StringLiteral) {
                continue;
            }

            $content = $node->getStringContentsText();
            if (!str_starts_with($content, 'app://') && !str_starts_with($content, 'page://')) {
                continue;
            }

            // 開きクォートの幅は書き方で変わる ('' も "" も heredoc もある)。
            // ノード本文の中で内容が始まる位置を実際に探す。
            $nodeText = $node->getText();
            $relative = strpos($nodeText, $content);
            if ($relative === false) {
                continue;
            }

            $start = $node->getStartPosition() + $relative;
            $ranges[] = [$start, $start + strlen($content)];
        }

        return $ranges;
    }
}
