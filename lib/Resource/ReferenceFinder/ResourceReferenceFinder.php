<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Resource\ReferenceFinder;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Resource\Model\ResourceTargetResolver;
use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Util\PathGuard;
use Suzumaze\BearPhpactor\Util\PhpClassDeclaration;
use Suzumaze\BearPhpactor\Util\ProjectLocator;
use Suzumaze\BearPhpactor\Util\ResourceObjectInheritance;
use FilesystemIterator;
use Generator;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Parser;
use Phpactor\ReferenceFinder\PotentialLocation;
use Phpactor\ReferenceFinder\ReferenceFinder;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\Location;
use Phpactor\TextDocument\TextDocument;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * リソースURIの文字列リテラル・リソースクラス宣言名から、そのリソースを参照する
 * 箇所 (textDocument/references) を探す。
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
    public function __construct(
        private StringLiteralAtOffset $stringLiteralAtOffset,
        private ResourceTargetResolver $resourceTargetResolver = new ResourceTargetResolver(),
        private Parser $parser = new Parser(),
    ) {
    }

    public function findReferences(TextDocument $document, ByteOffset $byteOffset): Generator
    {
        // 入口の安価な事前判定: リソースURI文字列 (app:// page://) も ResourceObject
        // の継承も無いドキュメントは参照検索の対象ではない。構文解析より先に降りる
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
        ) {
            return false;
        }

        $target = $this->targetAtOffset($document, $byteOffset);
        if ($target === null) {
            return false;
        }

        $uri = $document->uri();
        if ($uri === null || $uri->scheme() !== 'file') {
            return false;
        }

        $found = ProjectLocator::locate($uri->path());
        if ($found === null) {
            return false;
        }

        $targetRealPath = realpath($target);
        if ($targetRealPath === false) {
            return false;
        }

        // Project::locate() は dirname($file) をキーにリクエスト内でキャッシュする。
        // 同じディレクトリのファイルは同じ enclosing app dir を持つため安全。
        // キャッシュは findReferences() の呼び出しごとに作り直す (プロセスをまたが
        // ない)。同じ場所 (ファイル×開始位置) を二度yieldしないための履歴もここ。
        $projectCache = [];
        $seen = [];

        foreach ($found['psr4'] as $dirs) {
            foreach ($dirs as $dir) {
                $base = PathGuard::isAbsolutePath($dir) ? $dir : $found['root'] . '/' . $dir;
                if (!is_dir($base)) {
                    continue;
                }

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if (!$file->isFile() || $file->getExtension() !== 'php') {
                        continue;
                    }
                    $path = $file->getPathname();

                    // 生テキストに app:// / page:// を含むファイルだけを構文解析する。
                    // 文字列リテラルのノードから位置を取るため、コメントやdocblock中
                    // の記述は拾わない (BEAR.Kata実測: 245ファイル中78ファイル)。
                    $source = @file_get_contents($path);
                    if ($source === false) {
                        continue;
                    }
                    if (!str_contains($source, 'app://') && !str_contains($source, 'page://')) {
                        continue;
                    }

                    $rootNode = $this->parser->parseSourceFile($source, $path);
                    foreach ($rootNode->getDescendantNodes() as $node) {
                        if (!$node instanceof StringLiteral) {
                            continue;
                        }

                        $resourceUri = ResourceUri::fromString($node->getStringContentsText());
                        if ($resourceUri === null) {
                            continue;
                        }

                        $project = $projectCache[dirname($path)] ??= Project::locate($path);
                        if ($project === null) {
                            continue;
                        }

                        // そのファイルの位置から解決した先が対象Tと同じなら参照。
                        $resolved = $this->resourceTargetResolver->resolve($project, $resourceUri);
                        if ($resolved === null || realpath($resolved['file']) !== $targetRealPath) {
                            continue;
                        }

                        $start = $node->getStartPosition();
                        $key = $path . ':' . $start;
                        if (isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;

                        // クォート込みのリテラル全体を範囲にする (エディタの見た目)。
                        yield PotentialLocation::surely(
                            Location::fromPathAndOffsets($path, $start, $node->getEndPosition())
                        );
                    }
                }
            }
        }

        // 組込みの IndexedReferenceFinder を殺さない。false で鎖を続ける。
        return false;
    }

    /**
     * カーソル位置から対象Tを決める。
     *
     * (a) リソースURIの文字列リテラルの中 → そのドキュメント自身の位置から
     *     定義解決した先のファイル。解決できなければ null。
     * (b) BEAR\Resource\ResourceObject を継承したクラスの宣言名の上 →
     *     そのドキュメント自身のファイルパス。継承の判定は構文解析で行うため、
     *     docblock 中の "extends ResourceObject" という文言には反応しない。
     */
    private function targetAtOffset(TextDocument $document, ByteOffset $byteOffset): ?string
    {
        $offset = $byteOffset->toInt();

        // (a) リソースURI文字列リテラル
        $string = ($this->stringLiteralAtOffset)($document, $offset);
        if ($string !== null) {
            $resourceUri = ResourceUri::fromString($string[1]);
            if ($resourceUri !== null) {
                $uri = $document->uri();
                if ($uri !== null && $uri->scheme() === 'file') {
                    $project = Project::locate($uri->path());
                    if ($project !== null) {
                        $target = $this->resourceTargetResolver->resolve($project, $resourceUri);
                        if ($target !== null) {
                            return $target['file'];
                        }
                    }
                }
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
        $found = ProjectLocator::locate($path);
        if ($found === null) {
            return null;
        }
        $inheritance = new ResourceObjectInheritance($found['root'], $found['psr4'], $this->parser);
        if (!$inheritance->extendsResourceObject($class)) {
            return null;
        }

        return $path;
    }
}
