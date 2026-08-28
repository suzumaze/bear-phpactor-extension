<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Util;

/**
 * ファイルパスの安全性検査。ベースディレクトリの外へ出る相対パスを拒否する。
 *
 * 4機能 (Resource URI / SQL / Router / JsonSchema) の定義ジャンプは、いずれも
 * カーソル上の文字列をファイル名に変換してからベースディレクトリと結合する。
 * 「.. による脱出」「絶対パス」「バックスラッシュ区切り」の拒否をここに1か所
 * 集め、各機能は結合時に resolveInside() を通す。さらに結合後のパスがベース
 * ディレクトリの内側に収まることを最終確認する。
 */
final class PathGuard
{
    private function __construct()
    {
    }

    /**
     * ベースディレクトリに相対パスを結合し、正規化したパスを返す。
     * 次のいずれかに該当する場合は null を返す:
     *
     * - 相対パスが '..' を含む (親ディレクトリ参照)
     * - 相対パスが '\' を含む (OSによってはディレクトリ区切りとして扱われる)
     * - 相対パスが '/' またはドライブ文字 (C:\) で始まる (絶対パス)
     *
     * 上記の検査に加え、結合後にベースディレクトリの外へ出ないことも最終確認する。
     */
    public static function resolveInside(string $baseDir, string $relativePath): ?string
    {
        if (
            str_contains($relativePath, '..')
            || str_contains($relativePath, '\\')
            || self::isAbsolutePath($relativePath)
        ) {
            return null;
        }

        $baseDir = rtrim($baseDir, '/');
        $resolved = self::normalize($baseDir . '/' . $relativePath);

        if ($resolved !== $baseDir && !str_starts_with($resolved, $baseDir . '/')) {
            return null;
        }

        return $resolved;
    }

    /**
     * 絶対パスかどうか。'/' 始まり、またはドライブ文字 (C:/, C:\) 始まり。
     * psr-4 のディレクトリ解決 (Project::resolveDir) と resolveInside の両方が
     * この判定を共有する。
     */
    public static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    /**
     * パスを正規化する: '/' の連続と '.' セグメントを除き、'..' を1階分戻す。
     * 先頭に '/' を持つ絶対パスを前提とする。
     */
    private static function normalize(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        $normalized = implode('/', $segments);

        return $normalized === '' ? '/' : '/' . $normalized;
    }
}
