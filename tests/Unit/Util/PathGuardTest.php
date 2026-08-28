<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Util;

use Suzumaze\BearPhpactor\Util\PathGuard;
use PHPUnit\Framework\TestCase;

/**
 * 絶対パス判定の単体テスト。実機は macOS のため、psr-4 のディレクトリが
 * 'C:/src' のような Windows ドライブ文字付きでもプロジェクトルートが
 * 前置されないことを、判定関数 (PathGuard::isAbsolutePath) で担保する。
 */
final class PathGuardTest extends TestCase
{
    public function testIsAbsolutePath(): void
    {
        self::assertTrue(PathGuard::isAbsolutePath('/src'));
        self::assertTrue(PathGuard::isAbsolutePath('C:/src'));
        self::assertTrue(PathGuard::isAbsolutePath('C:\src'));
        self::assertTrue(PathGuard::isAbsolutePath('c:/src'));
        self::assertFalse(PathGuard::isAbsolutePath('src'));
        self::assertFalse(PathGuard::isAbsolutePath('./src'));
        self::assertFalse(PathGuard::isAbsolutePath('../src'));
    }

    public function testResolveInsideRejectsDriveLetterAbsolutePath(): void
    {
        // resolveInside も同じ判定を共有している (ドライブ文字付きは絶対パスとして拒否)
        self::assertNull(PathGuard::resolveInside('/base', 'C:/src/User.php'));
    }
}
