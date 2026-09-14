<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Template;

use Suzumaze\BearPhpactor\Util\PathGuard;

use function array_pop;
use function explode;
use function implode;
use function is_file;
use function str_contains;
use function str_replace;
use function str_starts_with;

/**
 * BEAR標準のTwig/Qiqローダー設定に沿ってテンプレート名を実ファイルへ変換する。
 */
final class TemplatePathResolver
{
    /** @var list<string> Madapaja.TwigModule AppPathProvider と同じ優先順 */
    public const TWIG_ROOTS = ['src/Resource', 'var/templates'];

    /** BEAR.QiqModuleのマニュアル・標準構成 */
    public const QIQ_ROOT = 'var/qiq/template';

    public function resolve(TemplateReference $reference, string $projectRoot, string $documentPath): ?string
    {
        if ($reference->engine === TemplateReference::ENGINE_TWIG) {
            return $this->resolveTwig($projectRoot, $reference->name);
        }

        if ($reference->engine === TemplateReference::ENGINE_QIQ) {
            return $this->resolveQiq($projectRoot, $documentPath, $reference->name);
        }

        return null;
    }

    private function resolveTwig(string $projectRoot, string $name): ?string
    {
        // Twigのnamespaced loader (@foo/bar) はBEAR標準の2ルートだけからは
        // 名前空間の対応先を確定できない。
        if (str_starts_with($name, '@') || str_contains($name, "\0")) {
            return null;
        }

        $relative = $this->normalizeTwigName($name);
        if ($relative === null || $relative === '') {
            return null;
        }

        foreach (self::TWIG_ROOTS as $root) {
            $path = PathGuard::resolveInside($projectRoot . '/' . $root, $relative);
            if ($path !== null && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function resolveQiq(string $projectRoot, string $documentPath, string $name): ?string
    {
        // collection:name はqiq_pathsの追加束縛が無いと対応先を決められない。
        if (str_contains($name, ':') || str_contains($name, "\0") || str_contains($name, '\\')) {
            return null;
        }

        $qiqRoot = $projectRoot . '/' . self::QIQ_ROOT;
        if (str_starts_with($name, '.')) {
            $path = $this->resolveQiqRelative($qiqRoot, $documentPath, $name);
        } else {
            // Qiq Catalogは root . '/' . name と連結するため、先頭 / があっても
            // POSIX上ではルート配下の二重スラッシュになる。ここでは正規化して扱う。
            $path = PathGuard::resolveInside($qiqRoot, ltrim($name, '/') . '.php');
        }

        return $path !== null && is_file($path) ? $path : null;
    }

    private function resolveQiqRelative(string $qiqRoot, string $documentPath, string $name): ?string
    {
        $rootPrefix = rtrim($qiqRoot, '/') . '/';
        if (!str_starts_with($documentPath, $rootPrefix)) {
            return null;
        }

        $current = substr($documentPath, strlen($rootPrefix));
        if (!str_ends_with($current, '.php')) {
            return null;
        }
        $currentName = substr($current, 0, -4);
        $base = explode('/', dirname($currentName) === '.' ? '' : dirname($currentName));
        if ($base === ['']) {
            $base = [];
        }

        // RenderStack::relative() と同じく、先頭の / と空白を落としてから ./ と ../
        // を再帰的に解決する。ルートを越える ../ はQiq本体でも失敗する。
        $relative = ltrim(trim($name), '/');
        while (str_starts_with($relative, './')) {
            $relative = substr($relative, 2);
        }
        while (str_starts_with($relative, '../')) {
            if ($base === []) {
                return null;
            }
            array_pop($base);
            $relative = substr($relative, 3);
        }

        // 正規化後にも .. が残れば、Qiq Catalog::split() が拒否する。
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $path = implode('/', [...$base, $relative]) . '.php';

        return PathGuard::resolveInside($qiqRoot, $path);
    }

    private function normalizeTwigName(string $name): ?string
    {
        $name = ltrim(str_replace('\\', '/', $name), '/');
        $segments = [];
        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }
}
