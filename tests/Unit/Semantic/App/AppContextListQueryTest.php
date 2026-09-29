<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\App;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\App\AppContextListQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

final class AppContextListQueryTest extends TestCase
{
    public function testFindsOnlyBearEntryPointCandidatesWithoutChoosingOne(): void
    {
        $result = (new AppContextListQuery())->listInWorkspace($this->workspace());
        self::assertNotNull($result->value);
        self::assertSame(
            ['cli-app', 'dev-html-app', 'forwarded-app', 'override-app', 'prod-app', 'prod-html-app'],
            array_column($result->value['items'], 'applicationContext'),
        );
        self::assertNull($result->value['selectedContext']);
        self::assertSame(4, $result->value['unresolvedTotal']);
        self::assertSame('public/index.php', $result->value['items'][0]['sources'][0]['path']);
        self::assertSame(11, $result->value['items'][0]['sources'][0]['line']);
        self::assertNotContains('Acme\\Shop', array_column($result->value['items'], 'applicationContext'));
        self::assertNotContains('unsafe-wrapper-app', array_column($result->value['items'], 'applicationContext'));
        self::assertNotContains('empty-wrapper-app', array_column($result->value['items'], 'applicationContext'));
        self::assertContains('context_argument_missing', array_column($result->value['unresolved'], 'reason'));
        self::assertSame('forwarded-app', $result->value['items'][2]['applicationContext']);
        self::assertSame('override-app', $result->value['items'][3]['applicationContext']);
        self::assertSame(
            [
                'BEAR\\Package\\Compiler\\Bootstrap::__invoke',
                'BEAR\\Package\\Injector::getInstance',
                'BEAR\\Package\\Injector::getOverrideInstance',
            ],
            $result->value['coverage']['supportedCalls'],
        );
        self::assertFalse($result->value['coverage']['runtimeUsageObserved']);
        self::assertSame(
            'static-positional-single-return-forwarders-only',
            $result->value['coverage']['wrapperResolution'],
        );
        self::assertFalse($result->value['coverage']['overrideModulesApplied']);
    }

    public function testPaginationAndIncompleteScanAreIndependent(): void
    {
        $result = (new AppContextListQuery())->listInWorkspace($this->workspace(), limit: 1, offset: 1);
        self::assertSame(6, $result->value['total']);
        self::assertTrue($result->value['truncated']);
        self::assertSame('dev-html-app', $result->value['items'][0]['applicationContext']);
        $limited = (new AppContextListQuery(0))->listInWorkspace($this->workspace());
        self::assertSame([], $limited->value['items']);
        self::assertTrue($limited->value['scanTruncated']);
        self::assertNull($limited->value['selectedContext']);
    }

    private function workspace(): WorkspaceContext
    {
        $workspace = WorkspaceContext::fromRoot(dirname(__DIR__, 3) . '/Fixture/DiComposition')->value;
        self::assertNotNull($workspace);

        return $workspace;
    }
}
