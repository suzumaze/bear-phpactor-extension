<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Alps;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Phpactor\ReferenceFinder\DefinitionLocator;
use Phpactor\ReferenceFinder\Exception\CouldNotLocateDefinition;
use Phpactor\ReferenceFinder\Exception\UnsupportedDocument;
use Phpactor\ReferenceFinder\TypeLocation;
use Phpactor\ReferenceFinder\TypeLocations;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\Location;
use Phpactor\TextDocument\TextDocument;
use Phpactor\WorseReflection\Core\TypeFactory;

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
 * addressable な記述子は `alps.descriptor` 以下を再帰的に検索する。ネストした
 * `descriptor` の `{"href": "#id"}` は参照として扱う。着地位置は該当記述子の
 * `"id"` キーの値の位置（JSON Schemaジャンプが `"title"` キーに着地させる
 * のと同じ流儀で、共有ALPS parserが求める）。
 */
final class AlpsDefinitionLocator implements DefinitionLocator
{
    private AlpsDescriptorAtOffset $descriptorAtOffset;

    public function __construct(
        StringLiteralAtOffset $stringLiteralAtOffset = new StringLiteralAtOffset(),
        private AlpsQuery $alpsQuery = new AlpsQuery(),
        ?AlpsDescriptorAtOffset $descriptorAtOffset = null,
    ) {
        $this->descriptorAtOffset = $descriptorAtOffset ?? new AlpsDescriptorAtOffset($stringLiteralAtOffset);
    }

    public function locateDefinition(TextDocument $document, ByteOffset $byteOffset): TypeLocations
    {
        if (!$document->language()->isPhp()) {
            throw new UnsupportedDocument(sprintf(
                'Language must be "php", got "%s"',
                $document->language()
            ));
        }

        $descriptor = ($this->descriptorAtOffset)($document, $byteOffset->toInt());
        if ($descriptor === null) {
            throw new CouldNotLocateDefinition('No BEAR.Sunday Alps attribute reference under the cursor');
        }
        [, $descriptorId] = $descriptor;

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
}
