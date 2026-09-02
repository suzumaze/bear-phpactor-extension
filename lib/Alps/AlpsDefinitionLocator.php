<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Alps;

use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Util\PathGuard;
use Suzumaze\BearPhpactor\Util\ProjectLocator;
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

    /** プロジェクトルート直下に置かれる、プロファイル場所を指定する設定ファイル */
    private const APIDOC_XML = 'apidoc.xml';

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
        $found = ProjectLocator::locate($uri->path());
        if ($found === null) {
            throw new CouldNotLocateDefinition(sprintf(
                'No composer.json with autoload.psr-4 above "%s"',
                $uri->path()
            ));
        }
        $root = $found['root'];

        $profilePath = $this->alpsProfilePath($root);
        if ($profilePath === null) {
            throw new CouldNotLocateDefinition(sprintf(
                'No ALPS profile configured in %s/%s',
                $root,
                self::APIDOC_XML
            ));
        }

        $contents = @file_get_contents($profilePath);
        if ($contents === false) {
            throw new CouldNotLocateDefinition(sprintf('Cannot read ALPS profile: %s', $profilePath));
        }

        $idOffset = $this->descriptorIdOffset($contents, $descriptorId);
        if ($idOffset === null) {
            throw new CouldNotLocateDefinition(sprintf(
                'ALPS descriptor "%s" not found in %s',
                $descriptorId,
                $profilePath
            ));
        }

        return new TypeLocations([
            new TypeLocation(
                TypeFactory::stringLiteral($descriptorId),
                Location::fromPathAndOffsets($profilePath, $idOffset, $idOffset)
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

    /**
     * `<プロジェクトルート>/apidoc.xml` の `<alps>` 要素が指すプロファイルJSONの
     * 絶対パス。次のどれかに該当する場合は null:
     *
     * - apidoc.xml が存在しない（機能が発火しないプロジェクト）
     * - apidoc.xml がXMLとして読めない
     * - `<alps>` 要素が無い・空
     * - パスが PathGuard に弾かれる（`..`・絶対パス・`\` 区切り）
     * - 指定されたプロファイルJSONが存在しない
     */
    private function alpsProfilePath(string $root): ?string
    {
        $apidocPath = $root . '/' . self::APIDOC_XML;
        if (!is_file($apidocPath)) {
            return null;
        }

        $xml = @simplexml_load_file($apidocPath);
        if ($xml === false) {
            return null;
        }

        $alps = $xml->alps;
        if ($alps === null) {
            return null;
        }

        $relativePath = trim((string) $alps);
        if ($relativePath === '') {
            return null;
        }

        $profilePath = PathGuard::resolveInside($root, $relativePath);

        return $profilePath === null ? null : (is_file($profilePath) ? $profilePath : null);
    }

    /**
     * プロファイルJSON内で、トップレベルの `alps.descriptor` に並ぶ該当記述子の
     * `"id"` キーの値（開きクォート位置）のバイトオフセット。構造として該当が
     * 無い・着地位置が探せない場合は null。
     */
    private function descriptorIdOffset(string $contents, string $descriptorId): ?int
    {
        if (!$this->hasTopLevelDescriptor($contents, $descriptorId)) {
            return null;
        }

        return $this->idValueOffset($contents, $descriptorId);
    }

    /**
     * トップレベルの `alps.descriptor` 配列に `id` === $descriptorId のエントリが
     * あるか。ネストした descriptor 配列の参照（`{"href": ...}`）は対象外なので
     * 見ない。JSONとして壊れていれば false（= 何もしない）。
     */
    private function hasTopLevelDescriptor(string $contents, string $descriptorId): bool
    {
        $data = json_decode($contents, true);
        if (
            !is_array($data)
            || !isset($data['alps'])
            || !is_array($data['alps'])
            || !isset($data['alps']['descriptor'])
            || !is_array($data['alps']['descriptor'])
        ) {
            return false;
        }

        foreach ($data['alps']['descriptor'] as $descriptor) {
            if (is_array($descriptor) && ($descriptor['id'] ?? null) === $descriptorId) {
                return true;
            }
        }

        return false;
    }

    /**
     * 生テキストを走査し、`"id"` キーの値が $descriptorId と一致する最初の位置
     * （値の開きクォートのオフセット）を返す。文字列リテラルとコメント (JSONC)
     * の外側だけを見る（JsonSchema の "title" キー検索と同じ流儀）。
     * 見つからなければ null。
     */
    private function idValueOffset(string $contents, string $descriptorId): ?int
    {
        $expected = json_encode($descriptorId);
        if ($expected === false) {
            return null;
        }

        $length = strlen($contents);
        for ($i = 0; $i < $length; $i++) {
            $char = $contents[$i];

            // コメント (JSONC) の中は飛ばす
            if ($char === '/' && ($contents[$i + 1] ?? '') === '/') {
                $newline = strpos($contents, "\n", $i);
                $i = $newline === false ? $length : $newline;
                continue;
            }
            if ($char === '/' && ($contents[$i + 1] ?? '') === '*') {
                $end = strpos($contents, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }

            if ($char !== '"') {
                continue;
            }

            $key = $this->rawJsonString($contents, $i);
            if ($key === null) {
                return null; // 閉じ引用符が無い壊れたJSON
            }

            if ($key !== '"id"') {
                $i += strlen($key) - 1;
                continue;
            }

            // "id" キーの値: ':' の後の文字列トークンを読む（値が文字列でない
            // か、一致しなければ次の "id" キーを探す）
            $j = $i + strlen($key);
            $isWhitespace = static fn (string $c): bool => $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r";
            while ($j < $length && $isWhitespace($contents[$j])) {
                $j++;
            }
            if (($contents[$j] ?? '') !== ':') {
                $i += strlen($key) - 1;
                continue;
            }
            $j++;
            while ($j < $length && $isWhitespace($contents[$j])) {
                $j++;
            }

            $value = $this->rawJsonString($contents, $j);
            if ($value === null) {
                $i += strlen($key) - 1;
                continue;
            }

            if ($value === $expected) {
                return $j; // 値の開きクォート位置
            }

            $i += strlen($key) - 1;
        }

        return null;
    }

    /**
     * $start に開きクォートがあるJSON文字列トークンを、エスケープを考慮して
     * 生テキストごと読む（`"id"` → `"id"`）。閉じ引用符が見つからなければ null。
     */
    private function rawJsonString(string $contents, int $start): ?string
    {
        $length = strlen($contents);
        if (($contents[$start] ?? '') !== '"') {
            return null;
        }

        $end = $start + 1;
        while ($end < $length) {
            if ($contents[$end] === '\\') {
                $end += 2;
                continue;
            }
            if ($contents[$end] === '"') {
                return substr($contents, $start, $end - $start + 1);
            }
            $end++;
        }

        return null;
    }
}
