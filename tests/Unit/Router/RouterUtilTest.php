<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Router;

use Suzumaze\BearPhpactor\Router\RouterUtil;
use PHPUnit\Framework\TestCase;

/**
 * idea-php-bearsunday-plugin の RouterUtilTest.java と同じ変換規則の検証。
 */
final class RouterUtilTest extends TestCase
{
    public function testSimpleResource(): void
    {
        self::assertSame('/Index.php', RouterUtil::toResourceFileName('/index'));
    }

    public function testNestedResource(): void
    {
        self::assertSame('/User/Profile.php', RouterUtil::toResourceFileName('/user/profile'));
    }

    public function testHyphenatedResource(): void
    {
        self::assertSame('/UserProfile.php', RouterUtil::toResourceFileName('/user-profile'));
    }

    public function testDeeplyNestedResource(): void
    {
        self::assertSame('/Admin/User/Setting.php', RouterUtil::toResourceFileName('/admin/user/setting'));
    }

    public function testHyphenatedNestedResource(): void
    {
        self::assertSame('/Api/UserProfile.php', RouterUtil::toResourceFileName('/api/user-profile'));
    }

    public function testEmptyPath(): void
    {
        self::assertSame('.php', RouterUtil::toResourceFileName(''));
    }

    public function testRootPath(): void
    {
        self::assertSame('/.php', RouterUtil::toResourceFileName('/'));
    }

    public function testTrailingSlash(): void
    {
        self::assertSame('/User/.php', RouterUtil::toResourceFileName('/user/'));
    }
}
