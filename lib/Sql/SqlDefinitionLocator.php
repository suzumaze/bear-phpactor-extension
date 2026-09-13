<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Sql;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Sql\SqlQuery;
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
    private SqlQueryAtOffset $sqlQueryAtOffset;

    public function __construct(
        StringLiteralAtOffset $stringLiteralAtOffset = new StringLiteralAtOffset(),
        private SqlQuery $sqlQuery = new SqlQuery(),
        ?SqlQueryAtOffset $sqlQueryAtOffset = null,
    ) {
        $this->sqlQueryAtOffset = $sqlQueryAtOffset ?? new SqlQueryAtOffset($stringLiteralAtOffset);
    }

    public function locateDefinition(TextDocument $document, ByteOffset $byteOffset): TypeLocations
    {
        if (!$document->language()->isPhp()) {
            throw new UnsupportedDocument(sprintf(
                'Language must be "php", got "%s"',
                $document->language()
            ));
        }

        $reference = ($this->sqlQueryAtOffset)($document, $byteOffset->toInt());
        if ($reference === null) {
            throw new CouldNotLocateDefinition('No BEAR.Sunday SQL query reference under the cursor');
        }
        $queryName = $reference[1];

        $uri = $document->uri();
        $project = $uri === null ? null : Project::locate($uri->path());
        if ($project === null) {
            throw new CouldNotLocateDefinition('No composer.json with autoload.psr-4 found above the document');
        }

        $result = $this->sqlQuery->resolve($project, $queryName);
        if ($result->status !== SemanticStatus::Ok || $result->value === null) {
            throw new CouldNotLocateDefinition(sprintf(
                'SQL file does not exist: %s/%s/%s.sql',
                $project->root(),
                'var/db/sql',
                $queryName
            ));
        }

        return new TypeLocations([
            new TypeLocation(
                TypeFactory::stringLiteral($queryName),
                Location::fromPathAndOffsets($result->value->file, 0, 0)
            ),
        ]);
    }
}
