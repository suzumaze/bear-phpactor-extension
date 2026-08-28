<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Resource\Model;

use Suzumaze\BearPhpactor\Util\PathGuard;
use Suzumaze\BearPhpactor\Util\ProjectLocator;
use FilesystemIterator;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\Expression\ObjectCreationExpression;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Parser;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * ImportApp ('tags', 'Acme\Tags', ...) のホスト名 → アプリ名前空間 の対応表。
 *
 * BEAR.Sunday は app://self/ の解決先をアプリ側で差し替えられる。別アプリの
 * 取り込み (ImportAppModule) もその1つで、app://tags/ が別パッケージの
 * リソースに向く。対象プロジェクトのPHPファイルを構文解析して
 * new ImportApp(...) を探し、第1・第2引数が文字列リテラルのときだけ対応表に載せる。
 *
 * 走査はプロジェクトごとに1度だけ行い、静的キャッシュで保持する (LSPサーバーは
 * 長命プロセスなので、リクエストのたびに走査しない)。
 */
final class ImportAppRegistry
{
    private const IMPORT_APP_CLASS = 'BEAR\Package\Module\Import\ImportApp';

    /** @var array<string, self> プロジェクトルート → レジストリ */
    private static array $byRoot = [];

    /** @var array<string, string>|null ホスト名 → アプリ名前空間 (初回解決時に構築) */
    private ?array $hostToNamespace = null;

    private function __construct(
        private string $root,
        private Parser $parser = new Parser(),
    ) {
    }

    public static function forProject(string $root): self
    {
        return self::$byRoot[$root] ??= new self($root);
    }

    /**
     * インポートされたホストのURIを、対応するパッケージ内のリソースクラスに解決する。
     * ホストが対応表に無い・パッケージの psr-4 に一致しない・クラスが無い場合は null。
     *
     * @return array{file: string, fqn: string}|null
     */
    public function resolve(ResourceUri $uri): ?array
    {
        $namespace = $this->hostToNamespace()[$uri->host()] ?? null;
        if ($namespace === null) {
            return null;
        }

        $fqn = $namespace . '\Resource\\' . $uri->classPath();

        // 取り込み先はインストール済みパッケージとは限らない。BEAR.Kata は
        // ImportApp('catalog', 'BEAR\Kata\Example\ImportedCatalog', 'app') の飛び先を
        // 自分の composer.json の autoload-dev に置いている。vendor だけ見ていたので
        // app://catalog/status に飛べなかった。まず vendor、無ければ自分の psr-4。
        $file = $this->fileInInstalledPackage($fqn) ?? $this->fileInThisProject($fqn);
        if ($file === null) {
            return null;
        }

        return ['file' => $file, 'fqn' => $fqn];
    }

    /** インストール済みパッケージ (vendor/composer/installed.json) から探す。 */
    private function fileInInstalledPackage(string $fqn): ?string
    {
        $match = InstalledPackageMap::forProject($this->root)->resolve($fqn);
        if ($match === null) {
            return null;
        }

        $relative = substr($fqn, strlen($match['prefix']));
        $file = PathGuard::resolveInside(
            $match['installPath'],
            $match['psr4Dir'] . '/' . str_replace('\\', '/', $relative) . '.php'
        );

        return $file !== null && is_file($file) ? $file : null;
    }

    /** このプロジェクト自身の psr-4 (autoload / autoload-dev) から探す。 */
    private function fileInThisProject(string $fqn): ?string
    {
        $found = ProjectLocator::locate($this->root . '/composer.json');
        if ($found === null) {
            return null;
        }

        foreach ($found['psr4'] as $prefix => $dirs) {
            if (!str_starts_with($fqn, $prefix)) {
                continue;
            }

            $relative = substr($fqn, strlen($prefix));
            foreach ($dirs as $dir) {
                $file = PathGuard::resolveInside(
                    $found['root'],
                    $dir . '/' . str_replace('\\', '/', $relative) . '.php'
                );
                if ($file !== null && is_file($file)) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string> ホスト名 → アプリ名前空間
     */
    private function hostToNamespace(): array
    {
        if ($this->hostToNamespace !== null) {
            return $this->hostToNamespace;
        }

        $map = [];
        foreach ($this->phpFiles() as $file) {
            $source = @file_get_contents($file);
            if ($source === false || !str_contains($source, 'ImportApp')) {
                continue;
            }
            $rootNode = $this->parser->parseSourceFile($source, $file);
            foreach ($rootNode->getDescendantNodes() as $node) {
                if (!$node instanceof ObjectCreationExpression) {
                    continue;
                }
                $designator = $node->classTypeDesignator;
                if (!$designator instanceof QualifiedName) {
                    continue;
                }
                $resolved = $designator->getResolvedName();
                if ($resolved === null || (string) $resolved !== self::IMPORT_APP_CLASS) {
                    continue;
                }
                $arguments = $this->stringArguments($node);
                if (count($arguments) < 2) {
                    continue;
                }
                $map[$arguments[0]] = $arguments[1];
            }
        }

        return $this->hostToNamespace = $map;
    }

    /**
     * new ImportApp(...) の第1・第2位置引数が文字列リテラルのとき、その値を返す。
     * どちらかが文字列リテラルでない呼び出しは対象外 (空配列)。
     *
     * @return list<string>
     */
    private function stringArguments(ObjectCreationExpression $node): array
    {
        $list = $node->argumentExpressionList;
        if ($list === null) {
            return [];
        }

        $values = [];
        foreach ($list->getChildNodes() as $child) {
            if (!$child instanceof ArgumentExpression) {
                continue;
            }
            $expression = $child->expression;
            if (!$expression instanceof StringLiteral) {
                return [];
            }
            $values[] = $expression->getStringContentsText();
            if (count($values) === 2) {
                break;
            }
        }

        return $values;
    }

    /**
     * プロジェクトのPHPファイル一覧 (vendor と隠しディレクトリは対象外)。
     *
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            foreach (explode('/', $path) as $segment) {
                if ($segment === 'vendor' || str_starts_with($segment, '.')) {
                    continue 2;
                }
            }
            $files[] = $path;
        }
        sort($files);

        return $files;
    }
}
