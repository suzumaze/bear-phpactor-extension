<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Router;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Resource\Model\ResourceTargetResolver;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Semantic\Route\RouteQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Util\PhpClassDeclaration;
use Microsoft\PhpParser\Parser;
use Phpactor\ReferenceFinder\DefinitionLocator;
use Phpactor\ReferenceFinder\Exception\CouldNotLocateDefinition;
use Phpactor\ReferenceFinder\Exception\UnsupportedDocument;
use Phpactor\ReferenceFinder\TypeLocation;
use Phpactor\ReferenceFinder\TypeLocations;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocument;
use Phpactor\WorseReflection\Core\TypeFactory;

/**
 * aura.route.php のルートパスから対応する Page リソースクラスへの定義ジャンプ。
 *
 * ルートパス '/index' をリソースURI page://self/index に読み替え、URI → クラスの
 * 解決は ResourceTargetResolver に委ねる (リソースURI側の定義ジャンプ・参照検索と
 * 共有。PLAN.md §2.11)。文脈接頭辞 (Content/・Admin/) の探索と camelCase の保持が
 * リソースURI側と同じ規則になる。
 *
 * 変換規則は idea-php-bearsunday-plugin の RouterGotoDeclarationHandler から
 * 意図的に離れている。プラグインは '/' と '-' の区切りで各語を
 * ucfirst(strtolower(...)) するため camelCase を失う ('/articleRedirector' →
 * Articleredirector)。実アプリ4本221ルートの測定で、その差は14本だった。
 * フレームワーク自身 (bear/resource の AppAdapter::__invoke() の
 * ucwords($uri->path, '/-')) は大小を保つため、こちらもそれに合わせる。
 *
 * リソースの起点は composer.json の autoload.psr-4 から取る (例:
 * "MyVendor\\MyProject\\": "src/" なら src/Resource/Page/Index.php を探す)。
 * プロジェクトルートは ProjectLocator、ルートパスの文字列抽出は
 * StringLiteralAtOffset、パスの安全検査は PathGuard と、5機能共通の部品を使う。
 */
final class RouterDefinitionLocator implements DefinitionLocator
{
    private const ROUTE_FILE = 'aura.route.php';

    private RouteQuery $routeQuery;
    private RouteReferenceAtOffset $routeReferenceAtOffset;

    public function __construct(
        private Parser $parser = new Parser(),
        StringLiteralAtOffset $stringLiteralAtOffset = new StringLiteralAtOffset(),
        ResourceTargetResolver $resourceTargetResolver = new ResourceTargetResolver(),
        ?RouteQuery $routeQuery = null,
        ?RouteReferenceAtOffset $routeReferenceAtOffset = null,
    ) {
        $this->routeQuery = $routeQuery ?? new RouteQuery($resourceTargetResolver);
        $this->routeReferenceAtOffset = $routeReferenceAtOffset ?? new RouteReferenceAtOffset($stringLiteralAtOffset);
    }

    public function locateDefinition(TextDocument $document, ByteOffset $byteOffset): TypeLocations
    {
        $uri = $document->uri();
        if ($uri === null || basename($uri->path()) !== self::ROUTE_FILE) {
            throw new UnsupportedDocument(sprintf('Not an Aura.Router route file: "%s"', (string) $uri));
        }

        $reference = ($this->routeReferenceAtOffset)($document, $byteOffset->toInt());
        if ($reference === null) {
            throw new CouldNotLocateDefinition('No route path string literal at the given offset');
        }
        $path = $reference[1];

        $project = Project::locate($uri->path());
        if ($project === null) {
            throw new CouldNotLocateDefinition(
                sprintf('Could not find composer.json with autoload.psr-4 above "%s"', $uri->path())
            );
        }

        $result = $this->routeQuery->resolve($project, $path);
        if ($result->status !== SemanticStatus::Ok || $result->value === null) {
            throw new CouldNotLocateDefinition(sprintf('No Page resource class for route path "%s"', $path));
        }
        $target = $result->value->resource;

        return new TypeLocations([
            new TypeLocation(
                TypeFactory::class($target->fqn),
                PhpClassDeclaration::location($target->file, $this->parser)
            ),
        ]);
    }
}
