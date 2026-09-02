<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Sql;

use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Util\PathGuard;
use Suzumaze\BearPhpactor\Util\ProjectLocator;
use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\DelimitedList\ArgumentExpressionList;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\PhpTokenizer;
use Microsoft\PhpParser\TokenKind;
use Phpactor\ReferenceFinder\DefinitionLocator;
use Phpactor\ReferenceFinder\Exception\CouldNotLocateDefinition;
use Phpactor\ReferenceFinder\Exception\UnsupportedDocument;
use Phpactor\ReferenceFinder\TypeLocation;
use Phpactor\ReferenceFinder\TypeLocations;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\Location;
use Phpactor\TextDocument\TextDocument;
use Phpactor\WorseReflection\Core\TypeFactory;
use Phpactor\WorseReflection\Core\Util\NodeUtil;

/**
 * SQLファイルへの定義ジャンプ（BEAR.Sundayの `var/db/sql/` 規約）。
 *
 * 次の2つの書き方のどちらにカーソルがあっても、同じSQLファイルへ飛ばす:
 *
 * - Ray.MediaQuery: `#[DbQuery('point_distance')]` の文字列リテラル上
 * - Ray.QueryModule: PHPDocの `@Query("point_distance")` アノテーションの名前の上
 *
 * SQLファイルは、カーソルのあるドキュメントから上へ psr-4 を持つ composer.json を
 * 辿って求めたプロジェクトルート直下の `var/db/sql/<名前>.sql`。クエリ名が
 * `../` などで SQL ディレクトリの外へ出ようとする場合や、ファイルが存在しない
 * 場合は候補を返さない（`CouldNotLocateDefinition`を投げ、連鎖の次のロケータに
 * 委ねる）。
 */
final class SqlDefinitionLocator implements DefinitionLocator
{
    /** プロジェクトルートからのSQL置き場（BEAR.Sunday規約） */
    private const SQL_DIR = 'var/db/sql';

    /** Ray.MediaQueryのDbQuery属性（useでの短縮名） */
    private const DB_QUERY_SHORT_NAME = 'DbQuery';

    /** Ray.MediaQueryのDbQuery属性（完全修飾名） */
    private const DB_QUERY_FQN = 'Ray\MediaQuery\Annotation\DbQuery';

    public function __construct(
        private StringLiteralAtOffset $stringLiteralAtOffset = new StringLiteralAtOffset(),
    ) {
    }

    public function locateDefinition(TextDocument $document, ByteOffset $byteOffset): TypeLocations
    {
        if (!$document->language()->isPhp()) {
            throw new UnsupportedDocument(sprintf(
                'Language must be "php", got "%s"',
                $document->language()
            ));
        }

        // 入口の安価な事前判定: ドキュメント全体に DbQuery / @Query のどちらも
        // 現れなければ、どの書き方でもクエリ名は取れない。構文解析より先に降りる。
        $text = $document->__toString();
        if (!str_contains($text, self::DB_QUERY_SHORT_NAME) && !str_contains($text, '@Query')) {
            throw new CouldNotLocateDefinition('No BEAR.Sunday SQL query reference under the cursor');
        }

        $queryName = $this->queryNameAtOffset($document, $byteOffset->toInt());
        if ($queryName === null) {
            throw new CouldNotLocateDefinition('No BEAR.Sunday SQL query reference under the cursor');
        }

        $root = $this->projectRoot($document);
        if ($root === null) {
            throw new CouldNotLocateDefinition('No composer.json with autoload.psr-4 found above the document');
        }

        $sqlFile = $this->resolveSqlFile($root, $queryName);
        if ($sqlFile === null) {
            throw new CouldNotLocateDefinition(sprintf(
                'SQL file does not exist: %s/%s/%s.sql',
                $root,
                self::SQL_DIR,
                $queryName
            ));
        }

        return new TypeLocations([
            new TypeLocation(
                TypeFactory::stringLiteral($queryName),
                Location::fromPathAndOffsets($sqlFile, 0, 0)
            ),
        ]);
    }

    /**
     * カーソル位置からクエリ名を取り出す。どちらの書き方でも無ければ null。
     */
    private function queryNameAtOffset(TextDocument $document, int $offset): ?string
    {
        $name = $this->queryNameFromDbQueryAttribute($document, $offset);
        if ($name !== null) {
            return $name;
        }

        return $this->queryNameFromQueryAnnotation($document->__toString(), $offset);
    }

    /**
     * `#[DbQuery('...')]` 属性の第1引数文字列上にカーソルがあれば名前を返す。
     *
     * 文字列リテラルの引き当ては共通部品 StringLiteralAtOffset に委ね (カーソルが
     * 文字列の内側にある場合のみ発火する)、そのノードから親を辿ってDbQuery属性の
     * 第1引数であることを確かめる。
     */
    private function queryNameFromDbQueryAttribute(TextDocument $document, int $offset): ?string
    {
        $literal = $this->stringLiteralAtOffset->literal($document, $offset);
        if ($literal === null) {
            return null;
        }

        $argument = $literal->getParent();
        if (!$argument instanceof ArgumentExpression || $argument->expression !== $literal) {
            return null;
        }

        $argumentList = $argument->getParent();
        if (!$argumentList instanceof ArgumentExpressionList) {
            return null;
        }

        // クエリ名は第1引数。type: 'row' のような後続引数ではジャンプしない。
        if (!isset($argumentList->children[0]) || $argumentList->children[0] !== $argument) {
            return null;
        }

        $attribute = $argumentList->getParent();
        if (!$attribute instanceof Attribute || !$this->isDbQueryAttribute($attribute)) {
            return null;
        }

        return $literal->getStringContentsText();
    }

    private function isDbQueryAttribute(Attribute $attribute): bool
    {
        $name = NodeUtil::nameFromTokenOrQualifiedName($attribute, $attribute->name);
        // 先頭の \ は同じ名前の別表記（use なし完全修飾の書き方）なので落とす
        $name = ltrim((string) $name, '\\');

        return $name === self::DB_QUERY_SHORT_NAME || $name === self::DB_QUERY_FQN;
    }

    /**
     * PHPDocの `@Query("...")` アノテーションの名前の上にカーソルがあれば返す。
     *
     * コメントも含むトークン列を引き、カーソルを含むdocblockコメントの中で
     * `@Query` の文字列引数と照合する。文字列リテラル中の `/*` などで誤検知しない。
     */
    private function queryNameFromQueryAnnotation(string $text, int $offset): ?string
    {
        foreach (PhpTokenizer::getTokensArrayFromContent($text, null, 0, false) as $token) {
            if ($token->kind !== TokenKind::DocCommentToken) {
                continue;
            }

            $comment = $token->getText($text);
            if ($comment === null || $comment === '') {
                continue;
            }

            $commentEnd = $token->start + strlen($comment);
            if ($offset < $token->start || $offset >= $commentEnd) {
                continue;
            }

            $name = $this->queryNameFromDocComment($comment, $token->start, $offset);
            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param string $comment docblockコメント本文（`/**` で始まる）
     * @param int $commentOffset コメント本文のドキュメント上の開始位置
     */
    private function queryNameFromDocComment(string $comment, int $commentOffset, int $offset): ?string
    {
        if (!str_starts_with($comment, '/**')) {
            return null;
        }

        if (
            preg_match_all(
                '/@Query\s*\(\s*([\'"])(?<name>[^\'"]+)\1/',
                $comment,
                $matches,
                PREG_OFFSET_CAPTURE
            ) === false
        ) {
            return null;
        }

        foreach ($matches['name'] as [$name, $relativeOffset]) {
            $nameStart = $commentOffset + $relativeOffset;
            if ($offset >= $nameStart && $offset < $nameStart + strlen($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * `<プロジェクトルート>/var/db/sql/<名前>.sql` を返す。クエリ名が SQL ディレクトリ
     * の外へ出る場合やファイルが無ければ null。
     */
    private function resolveSqlFile(string $root, string $queryName): ?string
    {
        $sqlFile = PathGuard::resolveInside($root . '/' . self::SQL_DIR, $queryName . '.sql');

        return $sqlFile === null ? null : (is_file($sqlFile) ? $sqlFile : null);
    }

    /**
     * ドキュメントの置かれたディレクトリから上へ、psr-4 を持つ composer.json を辿って
     * プロジェクトルートとする (5機能共通の ProjectLocator)。見つからなければ null。
     */
    private function projectRoot(TextDocument $document): ?string
    {
        $uri = $document->uri();
        if ($uri === null) {
            return null;
        }

        $found = ProjectLocator::locate($uri->path());

        return $found === null ? null : $found['root'];
    }
}
