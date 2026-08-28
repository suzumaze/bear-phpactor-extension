<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\JsonSchema;

use Suzumaze\BearPhpactor\Util\ProjectLocator;
use Microsoft\PhpParser\MissingToken;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\SourceFileNode;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Node\Statement\NamespaceDefinition;
use Microsoft\PhpParser\Parser;
use Phpactor\ReferenceFinder\Exception\UnsupportedDocument;
use Phpactor\ReferenceFinder\TypeLocation;
use Phpactor\ReferenceFinder\TypeLocations;
use Phpactor\ReferenceFinder\TypeLocator;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\Location;
use Phpactor\TextDocument\TextDocument;
use Phpactor\WorseReflection\Core\TypeFactory;

/**
 * クラス宣言名の上での JSON Schema 規約ジャンプ (textDocument/typeDefinition)。
 *
 * リソースクラス (名前空間に \Resource\App\ または \Resource\Page\ を含む) の
 * クラス名の上で「型定義へ移動」すると var/json_schema/<ケバブケース>.json に
 * 飛ぶ。BodyTypeDemo -> body-type-demo.json、Page\Admin\UserProfile ->
 * admin/user-profile.json。メソッド名はファイル名に含まれない。
 *
 * もとは JsonSchemaDefinitionLocator が textDocument/definition でこのジャンプを
 * 提供していた (PLAN.md §2.6 の③)。クラス宣言名の上のF12は VS Code の慣習では
 * 「その場に留まる」なので、定義ジャンプを上書きすると Shift の押し間違いが
 * 「壊れている」ように見える。そこで textDocument/typeDefinition に載せ替えた
 * (PLAN.md §2.6 の②の退避先)。リソースの本文の形を決めているのは JSON Schema
 * なので、「このリソースの型はどこか」への答えとして自然な位置でもある。
 *
 * 該当しないときは空の TypeLocations を返す (例外を投げない)。ChainTypeLocator
 * が捕まえるのは UnsupportedDocument だけで、CouldNotLocateType を投げると鎖が
 * 切れて組込みの型定義解決が二度と走らない。当拡張は列挙の先頭に居るので、
 * 全PHPコードの「型定義へ移動」を壊すことになる。
 *
 * 返すのは最大1件。複数返すと TypeDefinitionHandler が
 * window/showMessageRequest の選択プロンプトを出し、クライアントが答えないと
 * エラー通知が出る (ResourceTargetResolver のコメント参照)。
 *
 * プロジェクトルートはドキュメントの位置から解決する (ProjectLocator、他の
 * ロケータと共通)。着地はスキーマの "title" キーの位置 (無ければファイル先頭)。
 * 別アプリのスキーマには着地しない (入れ子のミニアプリは自身の var/json_schema
 * にしか解決しない)。
 */
final class JsonSchemaConventionTypeLocator implements TypeLocator
{
    public function __construct(
        private Parser $parser = new Parser(),
        private JsonSchemaPathResolver $schemaPathResolver = new JsonSchemaPathResolver(),
    ) {
    }

    public function locateTypes(TextDocument $document, ByteOffset $byteOffset): TypeLocations
    {
        if (!$document->language()->isPhp()) {
            throw new UnsupportedDocument(sprintf('Language must be php, got "%s"', $document->language()));
        }

        // 入口の安価な事前判定: クラス名規約は Resource\App / Resource\Page という
        // 名前空間がドキュメントに必ず現れる。どちらも無ければ構文解析より先に
        // 降りる (誤検出は許容、取りこぼしは無い)。空を返すので鎖は次へ進む。
        $text = $document->__toString();
        if (!str_contains($text, 'Resource\App') && !str_contains($text, 'Resource\Page')) {
            return new TypeLocations([]);
        }

        $uri = $document->uri();
        if ($uri === null) {
            return new TypeLocations([]);
        }
        $found = ProjectLocator::locate($uri->path());
        if ($found === null) {
            return new TypeLocations([]);
        }
        $root = $found['root'];

        $rootNode = $this->parser->parseSourceFile($document->__toString());
        $offset = $byteOffset->toInt();

        $schemaPath = $this->schemaPathFromClassConvention($rootNode, $offset, $root, $found['psr4'], $uri->path());
        if ($schemaPath === null) {
            return new TypeLocations([]);
        }

        // 着地はスキーマの "title" キーの位置 (無ければファイル先頭 (0,0))。
        // ファイル先頭に着地すると「なぜここに来たか」が読めないため。
        $titleOffset = $this->schemaPathResolver->titleKeyOffset($schemaPath);

        return new TypeLocations([
            new TypeLocation(
                TypeFactory::string(),
                Location::fromPathAndOffsets($schemaPath, $titleOffset, $titleOffset),
            ),
        ]);
    }

    /**
     * カーソルがリソースクラスのクラス名の上にあるとき、var/json_schema 規約で
     * スキーマファイルを解決する。クラス名の上でない・リソースクラスでない・
     * ファイルが実在しない場合は null。
     *
     * @param array<string, list<string>> $psr4
     */
    private function schemaPathFromClassConvention(
        SourceFileNode $rootNode,
        int $offset,
        string $root,
        array $psr4,
        string $filePath,
    ): ?string {
        $node = $rootNode->getDescendantNodeAtPosition($offset);
        if (!$node instanceof ClassDeclaration) {
            return null;
        }

        $name = $node->name;
        if ($name instanceof MissingToken) {
            return null;
        }
        if ($offset < $name->getStartPosition() || $offset > $name->getEndPosition()) {
            return null;
        }

        $namespace = $node->getNamespaceDefinition();
        if (!$namespace instanceof NamespaceDefinition || !$namespace->name instanceof QualifiedName) {
            return null;
        }

        return $this->schemaPathResolver->conventionPath(
            $root,
            $psr4,
            $filePath,
            $namespace->name->getText(),
            $name->getText($rootNode->fileContents),
        );
    }
}
