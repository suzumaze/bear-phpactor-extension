<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Resource\Util;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Parser;
use Phpactor\TextDocument\TextDocument;

/**
 * ドキュメントの指定バイトオフセットにあるPHP文字列リテラルを引き当てる。
 * 定義ジャンプと補完の両方で使う共通部品。
 */
final class StringLiteralAtOffset
{
    public function __construct(
        private Parser $parser = new Parser(),
    ) {
    }

    /**
     * @return array{0: int, 1: string}|null コンテンツ開始オフセット (開きクォート直後) と文字列の中身。
     *                                         カーソル位置が文字列リテラル内でなければ null。
     */
    public function __invoke(TextDocument $document, int $byteOffset): ?array
    {
        $literal = $this->literal($document, $byteOffset);
        if ($literal === null) {
            return null;
        }

        return [$literal->getStartPosition() + 1, $literal->getStringContentsText()];
    }

    /**
     * カーソル位置を含む文字列リテラルのノードを返す。カーソルが文字列の内側
     * (開きクォート〜閉じクォート手前) にある場合のみ。heredoc/nowdoc は対象外。
     *
     * 属性の引数検証 (親ノードの追跡) が必要なロケータは、このノードから
     * getParent() を辿る。
     */
    public function literal(TextDocument $document, int $byteOffset): ?StringLiteral
    {
        $text = $document->__toString();
        $node = $this->parser->parseSourceFile(
            $text,
            $document->uri()?->__toString(),
        )->getDescendantNodeAtPosition($byteOffset);

        $literal = $this->stringLiteralAncestor($node);
        if ($literal === null) {
            return null;
        }

        // 開きクォートは ' か " のみ対象 (heredoc/nowdoc は対象外)。
        // このパーサーforkでは単純な引用符文字列も startQuote が null で、
        // children が引用符込みの単一Tokenになるため、ノード先頭文字で判定する。
        $start = $literal->getStartPosition();
        $opening = substr($text, $start, 1);
        if ($opening !== "'" && $opening !== '"') {
            return null;
        }

        // getDescendantNodeAtPosition() はノードの終端位置を含むため、そのままでは
        // 閉じクォートの1バイト外でも発火してしまう。文字列の内側だけを対象にする。
        if ($byteOffset < $start || $byteOffset >= $literal->getEndPosition()) {
            return null;
        }

        return $literal;
    }

    private function stringLiteralAncestor(?Node $node): ?StringLiteral
    {
        while ($node !== null) {
            if ($node instanceof StringLiteral) {
                return $node;
            }
            $node = $node->parent;
        }

        return null;
    }
}
