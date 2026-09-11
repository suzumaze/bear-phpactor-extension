<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Alps;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\DelimitedList\ArgumentExpressionList;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
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
 * ALPSプロファイルへの定義ジャンプ（`#[Alps('doDeleteArticle')]` 属性）。
 *
 * `bear/api-doc` の `#[Alps('...')]` 属性（クラスレベル・メソッドレベル、
 * 繰り返し可能）の文字列リテラルにカーソルがあるとき、対応するALPSプロファイル
 * JSON内の記述子定義へ飛ばす。属性の書き方は3通り対応する: useで取り込んだ短縮名・
 * 完全修飾・先頭バックスラッシュ付き完全修飾（Ray.Di生成コードの書き方）。
 * 正規化はSQLジャンプの `isDbQueryAttribute()` と同じ（PLAN.md §2.19 の穴）。
 *
 * プロファイルJSONの場所に固定規約は無く、プロジェクトルート直下の `apidoc.xml`
 * の `<alps>` 要素が相対パスを持つ。次のどれかに該当するときは何も返さない
 * （投げる例外は連鎖の次のロケータへ委ねる形）:
 *
 * - `apidoc.xml` が存在しない / XMLとして読めない / `<alps>` 要素が無い
 * - `<alps>` のパスが `..` や絶対パスを含む（PathGuardが弾く）
 * - プロファイルJSONが存在しない / `alps.descriptor` に該当する記述子が無い
 *
 * 記述子は `alps.descriptor` 配列のトップレベルにフラットに並び、ネストした
 * `descriptor` の `{"href": "#id"}` は参照に過ぎない。着地位置は該当記述子の
 * `"id"` キーの値の位置（JSON Schemaジャンプが `"title"` キーに着地させる
 * のと同じ流儀で、テキスト走査で求める）。
 */
final class AlpsDefinitionLocator implements DefinitionLocator
{
    /** `bear/api-doc` の Alps 属性（useでの短縮名） */
    private const ALPS_SHORT_NAME = 'Alps';

    /** `bear/api-doc` の Alps 属性（完全修飾名） */
    private const ALPS_FQN = 'BEAR\ApiDoc\Annotation\Alps';

    public function __construct(
        private StringLiteralAtOffset $stringLiteralAtOffset = new StringLiteralAtOffset(),
        private AlpsQuery $alpsQuery = new AlpsQuery(),
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

        // 入口の安価な事前判定: ドキュメント全体に属性名が現れなければ、どの書き方
        // でも記述子IDは取れない。構文解析より先に降りる（誤検出は許容）。
        $text = $document->__toString();
        if (!str_contains($text, self::ALPS_SHORT_NAME)) {
            throw new CouldNotLocateDefinition('No BEAR.Sunday Alps attribute reference under the cursor');
        }

        $descriptorId = $this->descriptorIdAtOffset($document, $byteOffset->toInt());
        if ($descriptorId === null) {
            throw new CouldNotLocateDefinition('No BEAR.Sunday Alps attribute reference under the cursor');
        }

        $uri = $document->uri();
        if ($uri === null) {
            throw new CouldNotLocateDefinition('Document has no URI');
        }
        $project = Project::locate($uri->path());
        if ($project === null) {
            throw new CouldNotLocateDefinition(sprintf(
                'No composer.json with autoload.psr-4 above "%s"',
                $uri->path()
            ));
        }

        $result = $this->alpsQuery->resolve($project, $descriptorId);
        if ($result->status !== SemanticStatus::Ok || $result->value === null) {
            throw new CouldNotLocateDefinition(sprintf(
                'ALPS descriptor "%s" could not be resolved',
                $descriptorId
            ));
        }

        return new TypeLocations([
            new TypeLocation(
                TypeFactory::stringLiteral($descriptorId),
                Location::fromPathAndOffsets(
                    $result->value->profileFile,
                    $result->value->offset,
                    $result->value->offset,
                )
            ),
        ]);
    }

    /**
     * `#[Alps('...')]` 属性の第1引数文字列上にカーソルがあれば記述子IDを返す。
     *
     * 文字列リテラルの引き当ては共通部品 StringLiteralAtOffset に委ね（カーソルが
     * 文字列の内側にある場合のみ発火する）、そのノードから親を辿ってAlps属性の第1
     * 引数であることを確かめる。SQLジャンプの DbQuery と同じ構造。
     */
    private function descriptorIdAtOffset(TextDocument $document, int $offset): ?string
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

        // 記述子IDは第1引数。後続引数ではジャンプしない。
        if (!isset($argumentList->children[0]) || $argumentList->children[0] !== $argument) {
            return null;
        }

        $attribute = $argumentList->getParent();
        if (!$attribute instanceof Attribute || !$this->isAlpsAttribute($attribute)) {
            return null;
        }

        return $literal->getStringContentsText();
    }

    private function isAlpsAttribute(Attribute $attribute): bool
    {
        $name = NodeUtil::nameFromTokenOrQualifiedName($attribute, $attribute->name);
        // 先頭の \ は同じ名前の別表記（use なし完全修飾の書き方）なので落とす
        $name = ltrim((string) $name, '\\');

        return $name === self::ALPS_SHORT_NAME || $name === self::ALPS_FQN;
    }
}
