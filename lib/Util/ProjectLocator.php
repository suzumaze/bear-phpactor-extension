<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Util;

/**
 * プロジェクトルートと psr-4 マッピングの探索。
 *
 * 4機能 (Resource URI / SQL / Router / JsonSchema) は、いずれも「ドキュメントの
 * 位置から上へ composer.json を辿る」方式でプロジェクトルートを求める。この探索
 * ロジックはかつて3実装がバラバラだったが、ここに1つに統一した。
 */
final class ProjectLocator
{
    private function __construct()
    {
    }

    /**
     * ファイルパスから、それを含むプロジェクトを探す。
     *
     * ドキュメントのディレクトリから上へ composer.json を辿り、autoload.psr-4 を
     * 持つ最初のディレクトリをプロジェクトルートとする。psr-4 を持たない
     * composer.json はスキップしてさらに上を探す (monorepo のサブパッケージなど、
     * psr-4 を持たない composer.json は名前空間の起点にならないため)。
     *
     * @return array{root: string, psr4: array<string, list<string>>}|null
     */
    public static function locate(string $filePath): ?array
    {
        $dir = dirname($filePath);

        while (true) {
            $composerJson = $dir . '/composer.json';
            if (is_file($composerJson)) {
                $psr4 = self::readPsr4($composerJson);
                if ($psr4 !== []) {
                    return ['root' => $dir, 'psr4' => $psr4];
                }
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $dir = $parent;
        }
    }

    /**
     * ファイルパスから、そのファイルを含むアプリの根を返す。
     *
     * リソースツリー (/Resource/App/ または /Resource/Page/ の下) にあるファイル
     * の場合、そのマーカーの手前がアプリの根。例:
     * .../tests/Fake/Defer/Resource/App/Article.php → .../tests/Fake/Defer
     * リソースツリーの外にあるファイルは null。
     *
     * Project::enclosingAppDir() と JsonSchemaPathResolver の規約ジャンプが
     * 同じ計算を共有する。
     */
    public static function enclosingAppDir(string $filePath): ?string
    {
        if ($filePath === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', $filePath);
        foreach (['/Resource/App/', '/Resource/Page/'] as $marker) {
            $at = strpos($normalized, $marker);
            if ($at !== false) {
                return substr($normalized, 0, $at);
            }
        }

        return null;
    }

    /**
     * composer.json の psr-4 を [プレフィックス => ディレクトリの一覧] で返す。
     * プレフィックスは末尾 '\' 付き、ディレクトリは末尾 '/' 無しに正規化する。
     *
     * autoload と autoload-dev の両方を読み、同じプレフィックスは連結する。
     * psr-4 は 1プレフィックスに複数ディレクトリを許すため、値は常に配列で扱う。
     *
     * BEAR.Kata がこの3つを同時に使っている:
     *   autoload:      BEAR\Kata\ => src/
     *   autoload-dev:  BEAR\Kata\ => tests/                      (同じ名前空間の2つ目)
     *   autoload-dev:  BEAR\Kata\Example\ImportedCatalog\ => examples/…/src/
     * autoload しか読んでいなかったため、tests や examples に置かれたリソースへ
     * 飛べなかった。
     *
     * @return array<string, list<string>>
     */
    private static function readPsr4(string $composerJson): array
    {
        $json = json_decode((string) file_get_contents($composerJson), true);
        if (!is_array($json)) {
            return [];
        }

        $normalized = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            $psr4 = $json[$section]['psr-4'] ?? null;
            if (!is_array($psr4)) {
                continue;
            }

            foreach ($psr4 as $prefix => $dirs) {
                if (!is_string($prefix)) {
                    continue;
                }

                foreach ((array) $dirs as $dir) {
                    if (!is_string($dir)) {
                        continue;
                    }

                    $key = trim($prefix, '\\') . '\\';
                    $value = rtrim($dir, '/');
                    if (!in_array($value, $normalized[$key] ?? [], true)) {
                        $normalized[$key][] = $value;
                    }
                }
            }
        }

        return $normalized;
    }
}
