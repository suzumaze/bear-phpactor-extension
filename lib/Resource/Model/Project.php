<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Resource\Model;

use Suzumaze\BearPhpactor\Util\PathGuard;
use Suzumaze\BearPhpactor\Util\PhpClassDeclaration;
use Suzumaze\BearPhpactor\Util\ProjectLocator;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 対象プロジェクト (composer.json を持つディレクトリ) のモデル。
 *
 * リソースクラスの名前空間の起点は composer.json の autoload.psr-4 から取る。
 * 例: "Acme\Blog\": "src/" なら Resource ディレクトリは src/Resource、
 * クラス名前空間は Acme\Blog\Resource\App\User となる。
 * プロジェクトルートの探索は ProjectLocator に委譲する (4機能共通)。
 */
final class Project
{
    /**
     * @param array<string, list<string>> $psr4    psr-4プレフィックス → ディレクトリ一覧
     * @param string                      $fromPath このプロジェクトを引き当てたファイル
     */
    private function __construct(
        private string $root,
        private array $psr4,
        private string $fromPath = '',
    ) {
    }

    /**
     * ファイルパスから、それを含むプロジェクトを探す (composer.json を上方向に探索)。
     * psr-4設定を持つ composer.json が見つからなければ null。
     */
    public static function locate(string $filePath): ?self
    {
        $found = ProjectLocator::locate($filePath);
        if ($found === null) {
            return null;
        }

        return new self($found['root'], $found['psr4'], $filePath);
    }

    /**
     * プロジェクトルート (composer.json のあるディレクトリ)。
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * リソースURIに対応するクラスのファイルパス。
     * Resource ディレクトリを持つ psr-4 エントリが無い場合や、URIパスが
     * Resource ディレクトリの外へ出ようとする場合は null。
     */
    public function classFile(ResourceUri $uri): ?string
    {
        $root = $this->chooseRoot($uri);

        return $root === null ? null : PathGuard::resolveInside($root['dir'], $uri->filePath());
    }

    /**
     * リソースURIに対応するクラスの完全修飾名。
     */
    public function classFqn(ResourceUri $uri): ?string
    {
        $root = $this->chooseRoot($uri);

        return $root === null ? null : $root['ns'] . str_replace('/', '\\', $uri->classPath());
    }

    /**
     * 直接のリソースクラスが無いとき、1階層だけ深いディレクトリを探す。
     *
     * コンテキスト接頭辞 (AppAdapter の defaultSchemeType) で Resource/App/Content/ や
     * Resource/App/Admin/ に置かれるクラスを拾う。見つかった全部を返す。
     * 例: page://self/error-400 → Page/Content/Error400.php と Page/Admin/Error400.php。
     *
     * @return list<array{file: string, fqn: string}>
     */
    public function classFileCandidates(ResourceUri $uri): array
    {
        $roots = $this->resourceRoots();
        if ($roots === []) {
            return [];
        }

        $resourceDir = $roots[0]['dir'];

        $filePath = $uri->filePath(); // "Page/Error400.php"
        $slash = strpos($filePath, '/');
        if ($slash === false) {
            return [];
        }
        $schemeDir = substr($filePath, 0, $slash); // "Page"
        $rest = substr($filePath, $slash + 1);     // "Error400.php"

        $base = $resourceDir . '/' . $schemeDir;
        if (!is_dir($base)) {
            return [];
        }

        $candidates = [];
        $iterator = new FilesystemIterator($base, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            if (!$entry->isDir()) {
                continue;
            }
            $subdir = $entry->getFilename();
            $file = PathGuard::resolveInside($base, $subdir . '/' . $rest);
            if ($file === null || !is_file($file)) {
                continue;
            }
            $fqn = $roots[0]['ns'] . $schemeDir . '\\' . $subdir . '\\'
                . str_replace('/', '\\', substr($rest, 0, -4));
            $candidates[] = ['file' => $file, 'fqn' => $fqn];
        }

        usort($candidates, static fn (array $a, array $b): int => strcmp($a['file'], $b['file']));

        return $candidates;
    }

    /**
     * プロジェクトに実在するリソースクラスを、URI → 完全修飾名 で返す。
     * Resource/App と Resource/Page 以下の .php ファイルを走査し、
     * ResourceObject を継承しているものだけを対象にする。
     *
     * @return array<string, string> URI → クラスFQN (URIでソート済み)
     */
    public function resourceClasses(): array
    {
        $roots = $this->resourceRoots();
        if ($roots === []) {
            return [];
        }

        // 根を全部回る。tests/ や examples/ に置かれたアプリのリソースも候補に出す。
        // 同じURIが複数の根にあるときは先頭の根 (参照元自身のアプリ) を優先する。
        $classes = [];
        foreach ($roots as $root) {
            foreach (['App', 'Page'] as $schemeDir) {
                $dir = $root['dir'] . '/' . $schemeDir;
                if (!is_dir($dir)) {
                    continue;
                }

                foreach ($this->resourcePhpFiles($dir) as $file) {
                    $relativePath = substr($file, strlen($dir) + 1, -4); // "Blog/Posts"
                    $uriPath = implode('/', array_map('lcfirst', explode('/', $relativePath)));
                    $uri = sprintf('%s://self/%s', strtolower($schemeDir), $uriPath);
                    if (isset($classes[$uri])) {
                        continue;
                    }

                    $classes[$uri] = $root['ns'] . $schemeDir . '\\' . str_replace('/', '\\', $relativePath);
                }
            }
        }

        ksort($classes);

        return $classes;
    }

    /**
     * リソースの置き場所の一覧。先頭ほど優先。
     *
     * 1. **参照元ファイル自身のアプリ**。ドキュメントが .../Resource/App/ の下に
     *    いるなら、その Resource の親がそのアプリの根。BEAR.Kata は
     *    tests/Fake/Defer/ のようなテスト用の小さなアプリを複数持っており、
     *    その中の app://self/publish はそのアプリの中を指す。プロジェクトルートの
     *    psr-4 (src/) だけを見ていたので飛べなかった。
     * 2. psr-4 の各ディレクトリのうち Resource を持つもの。autoload-dev 由来の
     *    tests/ や examples/ もここに含まれる。
     *
     * @return list<array{dir: string, ns: string}>
     */
    private function resourceRoots(): array
    {
        $roots = [];

        $enclosing = $this->enclosingAppDir();
        if ($enclosing !== null) {
            $ns = $this->namespaceForDir($enclosing);
            if ($ns !== null) {
                $roots[] = ['dir' => $enclosing . '/Resource', 'ns' => $ns . 'Resource\\'];
            }
        }

        foreach ($this->psr4 as $prefix => $dirs) {
            foreach ($dirs as $dir) {
                $resourceDir = $this->resolveDir($dir) . '/Resource';
                if (!is_dir($resourceDir)) {
                    continue;
                }

                $root = ['dir' => $resourceDir, 'ns' => $prefix . 'Resource\\'];
                if (!in_array($root, $roots, true)) {
                    $roots[] = $root;
                }
            }
        }

        return $roots;
    }

    /**
     * 参照元ファイルが自分でリソースツリーの中にいるなら、そのアプリの根を返す。
     * 例: .../tests/Fake/Defer/Resource/App/Article.php → .../tests/Fake/Defer
     * 計算は ProjectLocator::enclosingAppDir() と共有する (JsonSchema の規約
     * ジャンプも同じ判定を使う)。
     */
    private function enclosingAppDir(): ?string
    {
        return ProjectLocator::enclosingAppDir($this->fromPath);
    }

    /**
     * ディレクトリに対応する名前空間プレフィックス。psr-4 のうち最も深く一致する
     * ものを採り、余った部分を名前空間に足す。
     * 例: psr-4 が BEAR\Kata\ => tests/ のとき tests/Fake/Defer → BEAR\Kata\Fake\Defer\
     */
    private function namespaceForDir(string $dir): ?string
    {
        $best = null;
        $bestLen = -1;

        foreach ($this->psr4 as $prefix => $dirs) {
            foreach ($dirs as $psr4Dir) {
                $base = rtrim($this->resolveDir($psr4Dir), '/');
                if ($dir !== $base && !str_starts_with($dir, $base . '/')) {
                    continue;
                }

                if (strlen($base) <= $bestLen) {
                    continue;
                }

                $bestLen = strlen($base);
                $rest = trim(substr($dir, strlen($base)), '/');
                $best = $rest === ''
                    ? $prefix
                    : $prefix . str_replace('/', '\\', $rest) . '\\';
            }
        }

        return $best;
    }

    /**
     * URIに対応するファイルが実在する最初の根を返す。
     * どこにも無ければ先頭の根 (従来どおり「存在しないパス」を返すため)。
     *
     * @return array{dir: string, ns: string}|null
     */
    private function chooseRoot(ResourceUri $uri): ?array
    {
        $roots = $this->resourceRoots();
        if ($roots === []) {
            return null;
        }

        foreach ($roots as $root) {
            $file = PathGuard::resolveInside($root['dir'], $uri->filePath());
            if ($file !== null && is_file($file)) {
                return $root;
            }
        }

        return $roots[0];
    }

    private function resolveDir(string $dir): string
    {
        return PathGuard::isAbsolutePath($dir) ? $dir : $this->root . '/' . $dir;
    }

    /**
     * ディレクトリ以下の ResourceObject を継承した .php ファイルの一覧。
     *
     * @return list<string>
     */
    private function resourcePhpFiles(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (!$this->extendsResourceObject($path)) {
                continue;
            }
            $files[] = $path;
        }
        sort($files);

        return $files;
    }

    /**
     * ファイル内のクラスが BEAR\Resource\ResourceObject を継承しているか。
     * 構文解析で判定するため、'extends MyResourceObject' や docblock 中の
     * 'extends ResourceObject' という文言にはマッチしない。
     */
    private function extendsResourceObject(string $path): bool
    {
        $class = PhpClassDeclaration::find($path);
        if ($class === null || $class->classBaseClause === null || $class->classBaseClause->baseClass === null) {
            return false;
        }
        $resolved = $class->classBaseClause->baseClass->getResolvedName();

        return $resolved !== null && (string) $resolved === 'BEAR\Resource\ResourceObject';
    }
}
