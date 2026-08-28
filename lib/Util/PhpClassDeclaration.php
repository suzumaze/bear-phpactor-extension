<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Util;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Parser;
use Phpactor\TextDocument\Location;

/**
 * PHPファイルのクラス宣言まわりの共通部品。
 *
 * 定義ジャンプの着地 (クラス名トークンの位置) と、リソースクラスかどうかの
 * 判定 (継承元クラス名) の2用途で、Resource / Router の両ロケータと
 * Project が使う。ファイルを tolerant-php-parser で構文解析して最初の
 * クラス宣言を探す。
 */
final class PhpClassDeclaration
{
    private function __construct()
    {
    }

    /**
     * ファイルを構文解析し、最初のクラス宣言を返す。クラスが無ければ null。
     */
    public static function find(string $file, ?Parser $parser = null): ?ClassDeclaration
    {
        $source = @file_get_contents($file);
        if ($source === false) {
            return null;
        }

        return self::findInSource($source, $file, $parser);
    }

    /**
     * ソース文字列から最初のクラス宣言を返す。$uri は構文解析時の表示名。
     *
     * エディタの未保存バッファ (ディスクと行がずれている) に対してトークン位置を
     * 求める用途では、ファイルではなくこちらを渡すこと。ディスクを読む find() と
     * メモリ上のドキュメントのオフセットを混ぜると、行が増えた瞬間に位置が
     * 合わなくなる (ResourceReferenceFinder::targetAtOffset() (b) の欠陥。
     * PLAN.md §2.10 の再演)。
     */
    public static function findInSource(string $source, ?string $uri = null, ?Parser $parser = null): ?ClassDeclaration
    {
        $parser ??= new Parser();
        $root = $parser->parseSourceFile($source, $uri);

        return self::findInNode($root);
    }

    /**
     * クラス宣言のクラス名トークンの位置を返す。クラスが無ければファイル先頭 (0,0)。
     */
    public static function location(string $file, ?Parser $parser = null): Location
    {
        $class = self::find($file, $parser);
        if ($class === null || $class->name === null) {
            return Location::fromPathAndOffsets($file, 0, 0);
        }

        return Location::fromPathAndOffsets($file, $class->name->getStartPosition(), $class->name->getEndPosition());
    }

    private static function findInNode(Node $node): ?ClassDeclaration
    {
        if ($node instanceof ClassDeclaration) {
            return $node;
        }
        foreach ($node->getChildNodes() as $child) {
            $class = self::findInNode($child);
            if ($class !== null) {
                return $class;
            }
        }

        return null;
    }
}
