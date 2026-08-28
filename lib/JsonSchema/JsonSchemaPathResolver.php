<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\JsonSchema;

use Suzumaze\BearPhpactor\Util\PathGuard;
use Suzumaze\BearPhpactor\Util\ProjectLocator;
use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Token;

/**
 * リソースクラスと JSON Schema ファイルの対応付けを解決する共通部品。
 *
 * 定義ジャンプ (JsonSchemaDefinitionLocator) と body キー補完
 * (BodyPropertyCompletor) の両方が、次の2つの出所でスキーマファイルを求める:
 *
 * 1. #[JsonSchema('user.json')] 属性 — schema: 名前付き引数または最初の位置引数
 *    がレスポンススキーマ、params: がリクエストスキーマ (var/json_validate)。
 * 2. var/json_schema 規約 — リソースクラス名から複数の流儀で候補を作り、
 *    実在する最初のものを採る。優先順位は固定 (先頭は従来のケバブ・
 *    サブディレクトリ。BodyTypeDemo -> body-type-demo.json、Page\Admin\UserProfile
 *    -> admin/user-profile.json)。
 *
 * いずれも PathGuard で var/ 配下に収まることを確認し、実在するファイルだけを
 * 返す。見つからなければ null (呼び出し側で「候補なし」として扱う)。
 */
final class JsonSchemaPathResolver
{
    public const RESPONSE_SCHEMA_DIR = 'var/json_schema';

    public const REQUEST_SCHEMA_DIR = 'var/json_validate';

    public const JSON_SCHEMA_ATTRIBUTE = 'JsonSchema';

    private const RESOURCE_APP_NAMESPACE = '\\Resource\\App\\';

    private const RESOURCE_PAGE_NAMESPACE = '\\Resource\\Page\\';

    private const CASE_KEBAB = 'kebab';

    private const CASE_CAMEL = 'camel';

    private const CASE_SNAKE = 'snake';

    /**
     * 属性の文字列リテラルが指すスキーマファイルを解決する。
     * params: 名前付き引数の場合はリクエストスキーマ (var/json_validate) を探す。
     * ファイル名がスキーマファイルでない・ファイルが実在しない場合は null。
     */
    public function attributePath(
        string $root,
        string $fileContents,
        StringLiteral $literal,
        ?ArgumentExpression $argument,
    ): ?string {
        $fileName = $literal->getStringContentsText();
        if ($fileName === '' || !str_contains($fileName, '.json')) {
            return null;
        }

        $directory = self::RESPONSE_SCHEMA_DIR;
        if (
            $argument !== null
            && $argument->name instanceof Token
            && $argument->name->getText($fileContents) === 'params'
        ) {
            $directory = self::REQUEST_SCHEMA_DIR;
        }

        // PathGuard がスキーマディレクトリの外への脱出 ('..') ・絶対パス・
        // '\' 区切りを拒否する。'admin/user.json' のようなサブパスは許容される。
        $path = PathGuard::resolveInside($root, $directory . '/' . $fileName);

        return $path === null ? null : (is_file($path) ? $path : null);
    }

    public function isJsonSchemaAttribute(Attribute $attribute): bool
    {
        $name = $attribute->name;
        if ($name instanceof QualifiedName) {
            $text = $name->getText();
            $separator = strrpos($text, '\\');
            $shortName = $separator === false ? $text : substr($text, $separator + 1);

            return $shortName === self::JSON_SCHEMA_ATTRIBUTE;
        }

        return false;
    }

    /**
     * スキーマファイルの "title" キーのバイトオフセット。無ければ 0 (ファイル先頭)。
     *
     * 定義ジャンプ (JsonSchemaDefinitionLocator) と型定義ジャンプ
     * (JsonSchemaConventionTypeLocator) の両方が着地位置に使う。ファイル先頭に
     * 着地すると「なぜここに来たか」が読めないため。
     *
     * JSON の構文解析はせず、文字列リテラルとコメントの外側で "title" キー
     * (閉じ引用符の直後に ':' が来る) を探す。文字列の値やコメントの中の
     * "title" には当たらない。
     */
    public function titleKeyOffset(string $schemaPath): int
    {
        $contents = @file_get_contents($schemaPath);
        if ($contents === false) {
            return 0;
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

            // 文字列の終端を探す (エスケープされた引用符は飛ばす)
            $end = $i + 1;
            while ($end < $length) {
                if ($contents[$end] === '\\') {
                    $end += 2;
                    continue;
                }
                if ($contents[$end] === '"') {
                    break;
                }
                $end++;
            }
            if ($end >= $length) {
                return 0; // 閉じ引用符が無い壊れたJSON
            }

            $content = substr($contents, $i + 1, $end - $i - 1);
            if ($content === 'title') {
                $j = $end + 1;
                $isWhitespace = static fn (string $c): bool => $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r";
                while ($j < $length && $isWhitespace($contents[$j])) {
                    $j++;
                }
                if ($j < $length && $contents[$j] === ':') {
                    return $i;
                }
            }

            $i = $end; // この文字列の次から続ける (for の $i++ で $end+1 へ)
        }

        return 0;
    }

    /**
     * クラス名規約 (var/json_schema/<候補>.json) でスキーマファイルを解決する。
     * リソース名前空間に属さないクラス、または候補がどれも実在しない場合は
     * null。
     *
     * 候補は複数の流儀 (ケバブ/キャメル/スネーク × サブディレクトリ/平坦化)
     * から組み立て、優先順位どおりに実在する最初のものを採る。順序は固定
     * (先頭は従来のケバブ・サブディレクトリ) なので、2つ以上実在しても
     * 黙って揺れない。重複した候補は取り除いてから試す。
     *
     * 規約ジャンプはそのクラスと同じアプリに属するスキーマにしか着地しない。
     * クラスが入れ子のリソースツリー (tests/Fake/…/Resource/App/ の形) にあり、
     * そのアプリ自身が var/json_schema を持たないなら降りる (プロジェクトルート
     * のスキーマへフォールスルーしない)。
     *
     * @param array<string, list<string>> $psr4 psr-4プレフィックス → ディレクトリ一覧
     */
    public function conventionPath(
        string $root,
        array $psr4,
        string $filePath,
        string $namespace,
        string $className,
    ): ?string {
        $segments = $this->resourceSegments($namespace, $className);
        if ($segments === []) {
            return null;
        }

        $schemaRoot = $this->schemaRoot($root, $psr4, $filePath);

        foreach ($this->conventionCandidates($segments) as $relativePath) {
            $path = PathGuard::resolveInside($schemaRoot, self::RESPONSE_SCHEMA_DIR . '/' . $relativePath);
            if ($path !== null && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * クラス名規約の候補 (スキーマディレクトリ相対)。優先順位は固定:
     *
     * 1. ケバブ・サブディレクトリ (cache/article-preview.json) — 従来の規則。
     *    互換のため先頭。2つ以上実在したときに黙って揺れない。
     * 2. キャメル・サブディレクトリ (cache/articlePreview.json)
     * 3. スネーク・平坦化 (cache_article_preview.json)
     * 4. キャメル・平坦化 (cacheArticlePreview.json)
     * 5. ケバブ・平坦化 (cache-article-preview.json)
     *
     * 重複は取り除く (単語1つのクラスでは5つ全部が同じ綴りになる)。
     *
     * @param list<string> $segments
     * @return list<string>
     */
    private function conventionCandidates(array $segments): array
    {
        $candidates = [
            $this->joinSegments($segments, self::CASE_KEBAB, '/'),
            $this->joinSegments($segments, self::CASE_CAMEL, '/'),
            $this->joinSegments($segments, self::CASE_SNAKE, '_'),
            $this->joinSegments($segments, self::CASE_CAMEL, ''),
            $this->joinSegments($segments, self::CASE_KEBAB, '-'),
        ];

        return array_values(array_unique($candidates));
    }

    /**
     * セグメントを1つの綴りに変換して separator で繋ぎ、'.json' を付ける。
     * $case は CASE_KEBAB / CASE_CAMEL / CASE_SNAKE。
     *
     * キャメル・平坦化 (separator が '') だけはセグメント単位ではなく、連結した
     * 全体を1つのキャメルケースにする (Cache\ArticlePreview -> cacheArticlePreview)。
     *
     * @param list<string> $segments
     */
    private function joinSegments(array $segments, string $case, string $separator): string
    {
        if ($case === self::CASE_CAMEL && $separator === '') {
            return lcfirst(implode('', $segments)) . '.json';
        }

        $converted = [];
        foreach ($segments as $segment) {
            $converted[] = match ($case) {
                self::CASE_CAMEL => lcfirst($segment),
                self::CASE_SNAKE => self::separatorCase($segment, '_'),
                default => self::separatorCase($segment, '-'),
            };
        }

        return implode($separator, $converted) . '.json';
    }

    /**
     * クラスの属するアプリのスキーマ根ディレクトリ。
     *
     * アプリの根 E (ファイルパスから /Resource/App/ または /Resource/Page/ の
     * 手前まで) が psr-4 ディレクトリのどれかと一致するならトップレベルのアプリ
     * なのでプロジェクトルート。一致しないなら入れ子のミニアプリなので E 自身
     * (E/var/json_schema が実在しなければ conventionPath は null を返す)。
     *
     * @param array<string, list<string>> $psr4
     */
    private function schemaRoot(string $root, array $psr4, string $filePath): string
    {
        $enclosing = ProjectLocator::enclosingAppDir($filePath);
        if ($enclosing === null || $this->isPsr4Dir($root, $psr4, $enclosing)) {
            return $root;
        }

        return $enclosing;
    }

    /**
     * ディレクトリが psr-4 のディレクトリのどれかと一致するか。
     * psr-4 のディレクトリはルート相対 (絶対パスはそのまま) で解決して比較する。
     *
     * @param array<string, list<string>> $psr4
     */
    private function isPsr4Dir(string $root, array $psr4, string $dir): bool
    {
        foreach ($psr4 as $dirs) {
            foreach ($dirs as $psr4Dir) {
                $base = rtrim(PathGuard::isAbsolutePath($psr4Dir) ? $psr4Dir : $root . '/' . $psr4Dir, '/');
                if ($dir === $base) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * リソース名のセグメント: クラス名と、\Resource\App\ または
     * \Resource\Page\ マーカーの後のサブ名前空間。リソース名前空間でなければ
     * 空配列。
     *
     * @return list<string>
     */
    public function resourceSegments(string $namespace, string $className): array
    {
        $normalized = '\\' . trim($namespace, '\\') . '\\';

        $subNamespace = null;
        foreach ([self::RESOURCE_APP_NAMESPACE, self::RESOURCE_PAGE_NAMESPACE] as $marker) {
            $index = strpos($normalized, $marker);
            if ($index !== false) {
                $subNamespace = substr($normalized, $index + strlen($marker));
                break;
            }
        }

        if ($subNamespace === null) {
            return [];
        }

        $segments = array_values(
            array_filter(
                explode('\\', $subNamespace),
                static fn (string $segment): bool => $segment !== ''
            )
        );
        $segments[] = $className;

        return $segments;
    }

    /**
     * PascalCase を区切り文字入りの小文字にする (BodyTypeDemo -> body-type-demo /
     * body_type_demo)。既存の kebabCase() を区切り文字で一般化したもの。
     * 参考実装 (idea-php-bearsunday-plugin) の kebabCase() と同じ。
     */
    private static function separatorCase(string $value, string $separator): string
    {
        $result = '';
        $previous = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $current = $value[$i];
            if ($current === '_' || $current === '-' || $current === ' ') {
                $result = self::appendSeparator($result, $separator);
                $previous = $current;
                continue;
            }
            if (
                ctype_upper($current)
                && $result !== ''
                && $previous !== ''
                && $previous !== '_'
                && $previous !== '-'
                && $previous !== ' '
                && !ctype_upper($previous)
            ) {
                $result .= $separator;
            }
            $result .= strtolower($current);
            $previous = $current;
        }

        return strtolower($result);
    }

    private static function appendSeparator(string $result, string $separator): string
    {
        if ($result !== '' && substr($result, -1) !== $separator) {
            return $result . $separator;
        }

        return $result;
    }
}
