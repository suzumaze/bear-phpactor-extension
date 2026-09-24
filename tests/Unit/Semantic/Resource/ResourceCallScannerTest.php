<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Resource;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceCallScanner;

final class ResourceCallScannerTest extends TestCase
{
    public function testExtractsOnlyDirectStaticCallsThroughSupportedResourceReceivers(): void
    {
        $source = <<<'PHP'
<?php
final class Client
{
    private const PREFIX = 'page://self/content/';

    public function request(string $dynamic): void
    {
        str_starts_with($dynamic, 'page://self/content/esi/');
        new \RuntimeException('app://self/intentionally-missing');
        $this->other->get('app://self/not-a-resource-client');
        $this->resource->get('app://self/user');
        $resource->post(uri: 'app://self/article');
        $this->resource->put($dynamic);
    }
}
PHP;
        $root = (new Parser())->parseSourceFile($source, '/workspace/src/Client.php');

        $facts = (new ResourceCallScanner())->scan($root, $source, '/workspace/src/Client.php');

        self::assertCount(2, $facts);
        self::assertSame(['get', 'post'], array_column($facts, 'call'));
        self::assertSame(
            ['app://self/user', 'app://self/article'],
            array_map(static fn ($fact): string => $fact->targetUri->uri(), $facts),
        );
        self::assertSame(['request', 'request'], array_column($facts, 'sourceMethod'));
        self::assertSame(['onGet', 'onPost'], array_column($facts, 'targetMethod'));
    }
}
