<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Resource\ReferenceFinder;

use Suzumaze\BearPhpactor\Resource\Model\ResourceTargetResolver;
use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Router\RouteReferenceAtOffset;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceReferencesQuery;
use Suzumaze\BearPhpactor\Semantic\Route\RouteQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Util\PhpClassDeclaration;
use Suzumaze\BearPhpactor\Util\ProjectLocator;
use Suzumaze\BearPhpactor\Util\ResourceObjectInheritance;
use Generator;
use Microsoft\PhpParser\Parser;
use Phpactor\ReferenceFinder\PotentialLocation;
use Phpactor\ReferenceFinder\ReferenceFinder;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\Location;
use Phpactor\TextDocument\TextDocument;

/**
 * リソースURIの文字列リテラル・リソースクラス宣言名・Route名から、その
 * リソースを参照する箇所 (textDocument/references) を探す。
 *
 * 参照の同一性は「URI文字列が同じ」ではなく「そのサイトの位置から URI を定義解決
 * した先のファイルが対象と同じ」で判定する。テスト用のミニアプリが同じ
 * 'app://self/article' を持っていても、参照元の属するアプリで解決するため
 * 混ざらない (PLAN.md §2.11)。
 *
 * 必ず return false で終わる。true を返すと ChainReferenceFinder が鎖を止め、
 * 組込みの IndexedReferenceFinder (通常のPHPクラス参照検索) が走らなくなる。
 */
final class ResourceReferenceFinder implements ReferenceFinder
{
    private RouteReferenceAtOffset $routeReferenceAtOffset;
    private RouteQuery $routeQuery;
    private ResourceReferencesQuery $resourceReferencesQuery;
    private ResourceQuery $resourceQuery;

    public function __construct(
        private StringLiteralAtOffset $stringLiteralAtOffset,
        ResourceTargetResolver $resourceTargetResolver = new ResourceTargetResolver(),
        private Parser $parser = new Parser(),
        ?RouteReferenceAtOffset $routeReferenceAtOffset = null,
        ?RouteQuery $routeQuery = null,
        ?ResourceReferencesQuery $resourceReferencesQuery = null,
        ?ResourceQuery $resourceQuery = null,
        private ?string $workspaceRoot = null,
    ) {
        $this->routeReferenceAtOffset = $routeReferenceAtOffset ?? new RouteReferenceAtOffset();
        $this->resourceQuery = $resourceQuery ?? new ResourceQuery();
        $this->routeQuery = $routeQuery ?? new RouteQuery($resourceTargetResolver, $this->resourceQuery);
        $this->resourceReferencesQuery = $resourceReferencesQuery ?? new ResourceReferencesQuery(
            $this->resourceQuery,
            $this->routeQuery,
            $this->routeReferenceAtOffset,
            $this->parser,
        );
    }

    public function findReferences(TextDocument $document, ByteOffset $byteOffset): Generator
    {
        // 入口の安価な事前判定: Resource URI、ResourceObject継承、aura.route.phpの
        // いずれでもないドキュメントは参照検索の対象ではない。構文解析より先に降りる
        // (LocatorEntryPointTest と同じ流儀。当拡張は連鎖の先頭に居るので、全PHP
        // ファイルの全参照検索で最初に走ることになる)。
        //
        // ただし間接継承のリソース (class Foo extends Bar で Bar が ResourceObject
        // を継承する形。PLAN.md §2.17 で実測した505本中21本) は、本文にこの3語を
        // 1つも含まない。リソースクラスは規約上 /Resource/App/ か /Resource/Page/
        // の下に置かれるので、パスにその区切りがあれば本文の検査に加えて通過
        // させる。文字列検査のみで構文解析はしない。リソースでないのに通過する
        // 誤検出は後段の継承チェックが空で落とす (LocatorEntryPointTest の注記:
        // 誤検出は許容、取りこぼしは禁止)。
        $text = $document->__toString();
        $path = $document->uri()?->path() ?? '';
        if (
            !str_contains($text, 'app://')
            && !str_contains($text, 'page://')
            && !str_contains($text, 'ResourceObject')
            && !str_contains($path, '/Resource/App/')
            && !str_contains($path, '/Resource/Page/')
            && basename($path) !== 'aura.route.php'
        ) {
            return false;
        }

        $uri = $document->uri();
        if ($uri === null || $uri->scheme() !== 'file') {
            return false;
        }

        $workspaceRoot = $this->workspaceRoot;
        if ($workspaceRoot === null) {
            $found = ProjectLocator::locate($uri->path());
            if ($found === null) {
                return false;
            }
            $workspaceRoot = $found['root'];
        }
        $workspace = WorkspaceContext::fromRoot($workspaceRoot);
        if ($workspace->value === null) {
            return false;
        }
        $context = $workspace->value->accessPolicy()->inspectExisting($uri->path());
        if ($context->value === null) {
            return false;
        }

        $origin = $this->originAtOffset(
            $document,
            $byteOffset,
            $workspace->value,
            $context->value->relative,
        );
        if ($origin === null) {
            return false;
        }

        if ($origin['kind'] === 'uri') {
            $result = $this->resourceReferencesQuery->findInWorkspace(
                $workspace->value,
                $origin['value'],
                $context->value->relative,
            );
        } elseif ($origin['kind'] === 'route') {
            $route = $this->routeQuery->resolveInWorkspace(
                $workspace->value,
                $origin['value'],
                $context->value->relative,
            );
            if ($route->status !== SemanticStatus::Ok || $route->value === null) {
                return false;
            }
            $result = $this->resourceReferencesQuery->findForResolutionInWorkspace(
                $workspace->value,
                $route->value->resource,
                $context->value->relative,
            );
        } else {
            $result = $this->resourceReferencesQuery->findForFileInWorkspace(
                $workspace->value,
                $origin['value'],
                $context->value->relative,
            );
        }

        if ($result->status !== SemanticStatus::Ok || $result->value === null) {
            return false;
        }

        foreach ($result->value->references as $reference) {
            yield PotentialLocation::surely(Location::fromPathAndOffsets(
                $reference->file,
                $reference->contentStart - 1,
                $reference->contentEnd + 1,
            ));
        }

        // 組込みの IndexedReferenceFinder を殺さない。false で鎖を続ける。
        return false;
    }

    /**
     * カーソル位置からResource URI、Route名、Resourceクラスを識別する。
     * 解決と参照走査はResourceReferencesQueryに委譲する。
     *
     * @return array{kind: 'uri'|'route'|'file', value: string}|null
     */
    private function originAtOffset(
        TextDocument $document,
        ByteOffset $byteOffset,
        WorkspaceContext $workspace,
        string $contextPath,
    ): ?array {
        $offset = $byteOffset->toInt();

        $route = ($this->routeReferenceAtOffset)($document, $offset);
        if ($route !== null) {
            return ['kind' => 'route', 'value' => $route[1]];
        }

        // (a) リソースURI文字列リテラル
        $string = ($this->stringLiteralAtOffset)($document, $offset);
        if ($string !== null) {
            $resourceUri = ResourceUri::fromString($string[1]);
            if ($resourceUri !== null) {
                return ['kind' => 'uri', 'value' => $resourceUri->uri()];
            }
        }

        // (b) リソースクラスの宣言名トークン
        //
        // ディスクではなくドキュメント (エディタが送ってきたバッファ) を構文解析
        // する。$offset はドキュメントのバイト位置なので、ディスクと行がずれて
        // いるとクラス名トークンの範囲から外れ、参照検索が黙って0件になる
        // (未保存の編集があると機能ごと止まる欠陥の修正。PLAN.md §2.10 の再演)。
        // 走査側がディスクを読むため未保存の #[Link] は現れない、という §2.11 の
        // 範囲の限界はそのまま (あちらは新しく書いた参照が見つからないだけで、
        // 機能は止まらない)。
        $uri = $document->uri();
        if ($uri === null || $uri->scheme() !== 'file') {
            return null;
        }
        $path = $uri->path();
        $class = PhpClassDeclaration::findInSource($document->__toString(), $path, $this->parser);
        if ($class === null || $class->name === null) {
            return null;
        }
        $name = $class->name;
        if ($offset < $name->getStartPosition() || $offset > $name->getEndPosition()) {
            return null;
        }
        if ($class->classBaseClause === null || $class->classBaseClause->baseClass === null) {
            return null;
        }
        // 継承の連鎖を辿る (class Foo extends Bar で Bar extends ResourceObject の
        // とき Foo もリソース。PLAN.md §2.17)。親クラスはディスクから読むため、
        // 未保存の編集は親クラス側には反映されない (ResourceObjectInheritance の
        // コメント参照)。
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return null;
        }
        $inheritance = new ResourceObjectInheritance(
            $project->value->root(),
            $project->value->psr4(),
            $this->parser,
        );
        if (!$inheritance->extendsResourceObject($class)) {
            return null;
        }

        return ['kind' => 'file', 'value' => $contextPath];
    }
}
