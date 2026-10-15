<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Aop;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;

use function Amp\Promise\wait;

final class AopSharedChainsTest extends TestCase
{
    public function testSharedChainsReconstructCompactItemsIncludingOrderAndDuplicates(): void
    {
        $handler = new SemanticQueryHandler(dirname(__DIR__, 3) . '/Fixture/AopShared');
        $compact = wait($handler->listAopApplications('app', includeUnknowns: true));
        $shared = wait($handler->listAopApplications('app', detail: 'shared', includeUnknowns: true));
        self::assertSame('ok', $shared['status']);
        self::assertCount(1, $shared['data']['chains']);
        foreach ($shared['data']['items'] as $index => $item) {
            $item['chain'] = $shared['data']['chains'][$item['chainId']];
            unset($item['chainId']);
            self::assertEquals($compact['data']['items'][$index], $item);
        }
        $first = wait($handler->listAopApplications('app', limit: 1, detail: 'shared'));
        $next = wait($handler->listAopApplications('app', limit: 1, offset: 1, detail: 'shared'));
        self::assertSame(1, $first['data']['nextOffset']);
        self::assertNull($next['data']['nextOffset']);
        self::assertSame($first['data']['chains'], $next['data']['chains']);
        $empty = wait($handler->listAopApplications('app', offset: 2, detail: 'shared'));
        self::assertSame([], $empty['data']['chains']);
    }

    public function testSharedOutputHasAnEndToEndSizeRegressionBudget(): void
    {
        $handler = new SemanticQueryHandler(dirname(__DIR__, 3) . '/Fixture/AopOutput');
        $compact = wait($handler->listAopApplications('app'));
        $shared = wait($handler->listAopApplications('app', detail: 'shared'));
        self::assertSame('ok', $shared['status']);
        self::assertCount(22, $shared['data']['items']);
        self::assertFalse($shared['data']['truncated']);
        self::assertLessThan(strlen(json_encode($compact)) / 2, strlen(json_encode($shared)));
        self::assertLessThan(20 * 1024, strlen(json_encode($shared)));
    }
}
