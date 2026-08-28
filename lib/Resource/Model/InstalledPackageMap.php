<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Resource\Model;

use Suzumaze\BearPhpactor\Util\PathGuard;

/**
 * 対象プロジェクトの vendor/composer/installed.json から、psr-4 プレフィックス →
 * パッケージ内ディレクトリ の対応表を引く。
 *
 * ImportApp で取り込まれた別アプリ (例: app://tags/ → Acme\Tags) の
 * リソースクラスは、そのパッケージの psr-4 に従って vendor 内で解決する。
 * installed.json はプロジェクトごとに1度だけ読み、静的キャッシュで保持する
 * (LSPサーバーは長命プロセスなので、リクエストのたびに読み直さない)。
 */
final class InstalledPackageMap
{
    /** @var array<string, self> プロジェクトルート → マップ */
    private static array $byRoot = [];

    /** @var array<string, array{installPath: string, psr4Dir: string}>|null プレフィックス → 場所 */
    private ?array $prefixes = null;

    private function __construct(
        private string $root,
    ) {
    }

    public static function forProject(string $root): self
    {
        return self::$byRoot[$root] ??= new self($root);
    }

    /**
     * FQN にマッチする最長の psr-4 プレフィックスを返す。無ければ null。
     *
     * @return array{prefix: string, installPath: string, psr4Dir: string}|null
     */
    public function resolve(string $fqn): ?array
    {
        $best = null;
        foreach ($this->prefixes() as $prefix => $location) {
            if (!str_starts_with($fqn, $prefix)) {
                continue;
            }
            if ($best === null || strlen($prefix) > strlen($best['prefix'])) {
                $best = ['prefix' => $prefix] + $location;
            }
        }

        return $best;
    }

    /**
     * @return array<string, array{installPath: string, psr4Dir: string}>
     */
    private function prefixes(): array
    {
        if ($this->prefixes !== null) {
            return $this->prefixes;
        }

        $installedJson = $this->root . '/vendor/composer/installed.json';
        if (!is_file($installedJson)) {
            return $this->prefixes = [];
        }

        $json = json_decode((string) file_get_contents($installedJson), true);
        $packages = is_array($json) ? ($json['packages'] ?? []) : [];
        if (!is_array($packages)) {
            return $this->prefixes = [];
        }

        $prefixes = [];
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $psr4 = $package['autoload']['psr-4'] ?? null;
            $installPath = $package['install-path'] ?? null;
            if (!is_array($psr4) || !is_string($installPath)) {
                continue;
            }
            // install-path は vendor/composer/ からの相対パス
            $installDir = PathGuard::isAbsolutePath($installPath)
                ? $installPath
                : dirname($installedJson) . '/' . $installPath;
            $installDir = realpath($installDir);
            if ($installDir === false) {
                continue;
            }
            foreach ($psr4 as $prefix => $dir) {
                if (!is_string($prefix) || !is_string($dir)) {
                    continue;
                }
                $prefixes[trim($prefix, '\\') . '\\'] = [
                    'installPath' => $installDir,
                    'psr4Dir' => rtrim($dir, '/'),
                ];
            }
        }

        return $this->prefixes = $prefixes;
    }
}
