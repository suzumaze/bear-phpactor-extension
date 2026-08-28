<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Resource\Model;

/**
 * BEAR.SundayリソースURI (app://self/user など) の値オブジェクト。
 *
 * スキームはクラスの置き場所 (app → Resource/App, page → Resource/Page)、
 * パスの各セグメントはパスカルケースのクラス名セグメントに対応する。
 * 例: app://self/blog-posting → App\BlogPosting, page://self/index → Page\Index
 */
final class ResourceUri
{
    /** @var array<string, string> スキーム → Resourceディレクトリ直下のクラス名 */
    private const SCHEME_CLASS_DIR = [
        'app' => 'App',
        'page' => 'Page',
    ];

    private function __construct(
        private string $scheme,
        private string $host,
        private string $path,
    ) {
    }

    /**
     * 文字列からリソースURIを組み立てる。リソースURIでなければ null。
     * 末尾のURIテンプレート ({?id} など) は無視する。
     */
    public static function fromString(string $value): ?self
    {
        // リソースの在り処を決めるのはパスだけ。URIテンプレートの展開 ({?id})、
        // クエリ文字列 (?a=b)、フラグメント (#x) は最初の1文字以降まとめて落とす。
        //
        // 実アプリの測定 (tools/coverage.php) で見つかった取りこぼし:
        //   app://self/article?id={id}                              (クエリ)
        //   app://self/auth#id                                      (フラグメント)
        //   app://self/report/metrics/device/button-click{?pjCode}&internalFlag=0
        //     (テンプレートが末尾ではなく途中にあり、後ろに & が続く)
        $value = (string) preg_replace('/[{?#].*$/s', '', $value);

        if (!preg_match('#^(?<scheme>[a-z]+)://(?<host>[^/]+)(?:/(?<path>.*))?$#', $value, $m)) {
            return null;
        }

        if (!isset(self::SCHEME_CLASS_DIR[$m['scheme']])) {
            return null;
        }

        $path = $m['path'] ?? '';
        $segments = array_values(array_filter(explode('/', $path), static fn (string $v): bool => $v !== ''));

        if ($segments === []) {
            return null;
        }

        return new self($m['scheme'], $m['host'], implode('/', $segments));
    }

    public function scheme(): string
    {
        return $this->scheme;
    }

    public function host(): string
    {
        return $this->host;
    }

    /**
     * URIパス。例: "blog/posts"
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * クラス名セグメント (スキーム対応ディレクトリ込み)。例: "App\Blog\Posts"
     */
    public function classPath(): string
    {
        $segments = array_map(self::pascalSegment(...), explode('/', $this->path));

        return self::SCHEME_CLASS_DIR[$this->scheme] . '\\' . implode('\\', $segments);
    }

    /**
     * リソースディレクトリからの相対ファイルパス。例: "App/Blog/Posts.php"
     */
    public function filePath(): string
    {
        return str_replace('\\', '/', $this->classPath()) . '.php';
    }

    /**
     * 正規化したURI文字列。例: "app://self/blog/posts"
     */
    public function uri(): string
    {
        return sprintf('%s://%s/%s', $this->scheme, $this->host, $this->path);
    }

    /**
     * パスセグメントをパスカルケースに変換する。
     * ハイフン区切りは各語を大文字に、既存の大文字は保つ。
     * blog-posting → BlogPosting, blogPosting → BlogPosting, user → User
     */
    private static function pascalSegment(string $segment): string
    {
        return implode('', array_map('ucfirst', explode('-', $segment)));
    }
}
