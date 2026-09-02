<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Router;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Resource\Model\ResourceTargetResolver;
use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Util\PhpClassDeclaration;
use Microsoft\PhpParser\Node\DelimitedList\ArgumentExpressionList;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Parser;
use Phpactor\ReferenceFinder\DefinitionLocator;
use Phpactor\ReferenceFinder\Exception\CouldNotLocateDefinition;
use Phpactor\ReferenceFinder\Exception\UnsupportedDocument;
use Phpactor\ReferenceFinder\TypeLocation;
use Phpactor\ReferenceFinder\TypeLocations;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocument;
use Phpactor\WorseReflection\Core\TypeFactory;
use Phpactor\WorseReflection\Core\Util\NodeUtil;

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

    /**
     * ルート定義のメソッド名。Aura.Router の Map クラスで ($name, $path, $handler = null)
     * という同じ引数形を持つ8つ: route と HTTP メソッド別ショートカットの
     * get / post / put / patch / delete / head / options。第1引数はどれもルート名。
     * attach ($namePrefix, $pathPrefix, callable $callable) は形が違い、第1引数が
     * 名前の接頭辞であってルート名ではないため入れない。
     */
    private const ROUTE_METHOD_NAMES = ['route', 'get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

    public function __construct(
        private Parser $parser = new Parser(),
        private StringLiteralAtOffset $stringLiteralAtOffset = new StringLiteralAtOffset(),
        private ResourceTargetResolver $resourceTargetResolver = new ResourceTargetResolver(),
    ) {
    }

    public function locateDefinition(TextDocument $document, ByteOffset $byteOffset): TypeLocations
    {
        $uri = $document->uri();
        if ($uri === null || basename($uri->path()) !== self::ROUTE_FILE) {
            throw new UnsupportedDocument(sprintf('Not an Aura.Router route file: "%s"', (string) $uri));
        }

        $path = $this->routePathAtOffset($document, $byteOffset);
        if ($path === null) {
            throw new CouldNotLocateDefinition('No route path string literal at the given offset');
        }

        $project = Project::locate($uri->path());
        if ($project === null) {
            throw new CouldNotLocateDefinition(
                sprintf('Could not find composer.json with autoload.psr-4 above "%s"', $uri->path())
            );
        }

        // ルートパス ('/index') は page://self のパス部分とみなす。パスカルケース
        // 変換 (既存の大文字は保つ) と文脈接頭辞の探索は ResourceUri / Project が
        // 担う。'..' を含むルートパスは ResourceUri のパスとして不正になり、
        // 解決先が Resource ディレクトリの外へ出るため PathGuard が拒否する。
        $resourceUri = ResourceUri::fromString('page://self' . $path);
        if ($resourceUri === null) {
            throw new CouldNotLocateDefinition(sprintf('Route path "%s" is not a valid resource path', $path));
        }

        $target = $this->resourceTargetResolver->resolve($project, $resourceUri);
        if ($target === null) {
            throw new CouldNotLocateDefinition(sprintf('No Page resource class for route path "%s"', $path));
        }

        return new TypeLocations([
            new TypeLocation(
                TypeFactory::class($target['fqn']),
                PhpClassDeclaration::location($target['file'], $this->parser)
            ),
        ]);
    }

    /**
     * カーソル位置にある文字列リテラルがルートパス ('/' 始まり) ならその内容を返す。
     * 文字列リテラルの引き当ては共通部品 StringLiteralAtOffset に委ねる (文字列の
     * 内側にカーソルがある場合のみ発火する)。
     *
     * ルートパスは $map->route(...) の第1引数 (ルート名) だけ。第2引数は HTTP の
     * URL パターンでリソースとは無関係 ('/blogs/{blogger}' から飛ぶと
     * Page/Blogs.php に着地してしまう)。文字列リテラルのノードから親を辿って
     * 確かめる (SqlDefinitionLocator::queryNameFromDbQueryAttribute() と同じ形)。
     */
    private function routePathAtOffset(TextDocument $document, ByteOffset $byteOffset): ?string
    {
        $literal = $this->stringLiteralAtOffset->literal($document, $byteOffset->toInt());
        if ($literal === null) {
            return null;
        }
        $contents = $literal->getStringContentsText();
        if (!str_starts_with($contents, '/')) {
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

        // ルート名は第1引数。第2引数 (URLパターン) ではジャンプしない。
        if (!isset($argumentList->children[0]) || $argumentList->children[0] !== $argument) {
            return null;
        }

        $call = $argumentList->getParent();
        if (!$call instanceof CallExpression || !$this->isRouteCall($call)) {
            return null;
        }

        return $contents;
    }

    /**
     * 呼び出し名がルート定義のメソッドであること ($map->route(...) や $map->get(...) の形。
     * 変数名はアプリによって変わるため決め打ちしない)。メソッド連鎖
     * ($map->route(...)->tokens([...])) の tokens 呼び出しはここで弾かれる。
     *
     * 受け入れる名前は ROUTE_METHOD_NAMES の8つ (Aura.Router の Map クラスで
     * ($name, $path, $handler = null) という同じ引数形を持つ route と HTTP メソッド別
     * ショートカット)。第1引数はどれもルート名。attach は第1引数が名前の接頭辞なので
     * ルート名ではなく、ルートパスとして解決できない (誤って受け入れると名前の接頭辞
     * から Page クラスへ飛んでしまう)。
     */
    private function isRouteCall(CallExpression $call): bool
    {
        $callable = $call->callableExpression;
        if ($callable instanceof MemberAccessExpression) {
            return in_array(
                NodeUtil::nameFromTokenOrNode($call, $callable->memberName),
                self::ROUTE_METHOD_NAMES,
                true
            );
        }
        if ($callable instanceof QualifiedName) {
            // 先頭の \ は同じ名前の別表記 (完全修飾の書き方) なので落とす
            return in_array(ltrim($callable->__toString(), '\\'), self::ROUTE_METHOD_NAMES, true);
        }

        return false;
    }
}
