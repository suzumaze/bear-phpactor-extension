<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Resource;

use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use PHPUnit\Framework\TestCase;

final class ResourceUriTest extends TestCase
{
    public function testParsesSimpleAppUri(): void
    {
        $uri = ResourceUri::fromString('app://self/user');
        self::assertNotNull($uri);
        self::assertSame('app', $uri->scheme());
        self::assertSame('self', $uri->host());
        self::assertSame('user', $uri->path());
        self::assertSame('App\User', $uri->classPath());
        self::assertSame('App/User.php', $uri->filePath());
        self::assertSame('app://self/user', $uri->uri());
    }

    public function testParsesPageUri(): void
    {
        $uri = ResourceUri::fromString('page://self/index');
        self::assertNotNull($uri);
        self::assertSame('Page\Index', $uri->classPath());
        self::assertSame('Page/Index.php', $uri->filePath());
    }

    public function testParsesNestedPath(): void
    {
        $uri = ResourceUri::fromString('app://self/blog/posts');
        self::assertNotNull($uri);
        self::assertSame('App\Blog\Posts', $uri->classPath());
        self::assertSame('App/Blog/Posts.php', $uri->filePath());
    }

    public function testParsesHyphenatedSegment(): void
    {
        $uri = ResourceUri::fromString('app://self/blog-posting');
        self::assertNotNull($uri);
        self::assertSame('App\BlogPosting', $uri->classPath());
    }

    public function testKeepsInnerCapitalOfCamelCaseSegment(): void
    {
        $uri = ResourceUri::fromString('app://self/blogPosting');
        self::assertNotNull($uri);
        self::assertSame('App\BlogPosting', $uri->classPath());
    }

    public function testIgnoresUriTemplate(): void
    {
        $uri = ResourceUri::fromString('app://self/user{?id}');
        self::assertNotNull($uri);
        self::assertSame('App\User', $uri->classPath());
    }

    /**
     * クエリ文字列はリソースの在り処に関係しない。
     * 実アプリの測定 (tools/coverage.php) で取りこぼしとして出てきたケース。
     */
    public function testIgnoresQueryString(): void
    {
        $uri = ResourceUri::fromString('app://self/article?id={id}');

        self::assertNotNull($uri);
        self::assertSame('article', $uri->path());
    }

    public function testIgnoresQueryStringOnNestedPath(): void
    {
        $uri = ResourceUri::fromString('app://self/contents-api/nav?part=footer');

        self::assertNotNull($uri);
        self::assertSame('contents-api/nav', $uri->path());
    }

    /** フラグメントも同様。実アプリでは 'app://self/auth#id' の形で5箇所あった。 */
    public function testIgnoresFragment(): void
    {
        $uri = ResourceUri::fromString('app://self/auth#id');

        self::assertNotNull($uri);
        self::assertSame('auth', $uri->path());
    }

    /**
     * URIテンプレートが末尾ではなく途中にあり、後ろに & が続く形。
     * 実アプリの #[Embed] で使われていて、取りこぼしていた。
     */
    public function testIgnoresUriTemplateFollowedByMoreQuery(): void
    {
        $uri = ResourceUri::fromString('app://self/report/metrics/device/button-click{?pjCode}&internalFlag=0');

        self::assertNotNull($uri);
        self::assertSame('report/metrics/device/button-click', $uri->path());
    }

    public function testRejectsNonResourceUri(): void
    {
        self::assertNull(ResourceUri::fromString('query://self/user'));
        self::assertNull(ResourceUri::fromString('app://self/'));
        self::assertNull(ResourceUri::fromString('app://self'));
        self::assertNull(ResourceUri::fromString('not-a-uri'));
        self::assertNull(ResourceUri::fromString(''));
    }
}
