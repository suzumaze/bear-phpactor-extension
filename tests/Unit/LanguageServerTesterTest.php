<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Phpactor\LanguageServer\LanguageServerTesterBuilder;
use Phpactor\LanguageServerProtocol\InitializeResult;

/**
 * エディタ無しでLSPサーバーを組み立て、応答が返ることを確認する最小テスト。
 * 今後の機能開発はこのテスターにハンドラを足して検証する。
 */
final class LanguageServerTesterTest extends TestCase
{
    public function testServerStartsAndRespondsToInitialize(): void
    {
        $tester = LanguageServerTesterBuilder::create()->build();

        $result = $tester->initialize();

        self::assertInstanceOf(InitializeResult::class, $result);
        self::assertSame('unspecified', $result->serverInfo['name']);
    }
}
