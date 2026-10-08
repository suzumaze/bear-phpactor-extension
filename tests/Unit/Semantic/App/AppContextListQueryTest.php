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
        self::assertSame('positional-parameter-forwarders', $result->value['coverage']['wrapperResolution']);
        self::assertFalse($result->value['coverage']['overrideModulesApplied']);
    }

    public function testFollowsContextsPassedToClosuresAndInvokablesOfASkeletonApp(): void
    {
        $result = (new AppContextListQuery())->listInWorkspace($this->workspace('ContextForwarders'));
        self::assertNotNull($result->value);
        $items = array_column($result->value['items'], null, 'applicationContext');
        self::assertSame(
            [
                'arrow-app',
                'batch-app',
                'cli-hal-app',
                'hal-app',
                'html-hal-app',
                'prod-admin-app',
                'prod-compile-app',
                'prod-hal-app',
            ],
            array_keys($items),
        );

        // (require <file>)('…') where the file returns a closure, including a ternary of two literals.
        self::assertSame('public/index.php', $items['prod-hal-app']['sources'][0]['path']);
        self::assertSame('public/index.php', $items['hal-app']['sources'][0]['path']);
        // (new C())(…) and (new C())->method(…), where the method reaches the app's own static forwarder.
        self::assertSame('bin/app.php', $items['cli-hal-app']['sources'][0]['path']);
        self::assertSame('bin/page.php', $items['html-hal-app']['sources'][0]['path']);
        // A closure variable called in the file that defines it, plus an arrow function.
        self::assertSame('bin/compile.php', $items['prod-compile-app']['sources'][0]['path']);
        self::assertSame('bin/compile.php', $items['prod-admin-app']['sources'][0]['path']);
        self::assertSame('bin/compile.php', $items['arrow-app']['sources'][0]['path']);
        // Literal contexts in bootstrap/ are scanned too.
        self::assertSame('bootstrap/batch.php', $items['batch-app']['sources'][0]['path']);

        // Forwarders that were called leave no unresolved site behind. Only the five callables in
        // negatives.php that cannot be followed keep the inner call visible.
        self::assertSame(5, $result->value['unresolvedTotal']);
        self::assertSame(
            ['bin/negatives.php'],
            array_values(array_unique(array_column($result->value['unresolved'], 'path'))),
        );
        self::assertSame(
            ['context_expression_unresolved'],
            array_values(array_unique(array_column($result->value['unresolved'], 'reason'))),
        );
        self::assertSame(
            ['static-method', 'instance-method', 'closure-returned-by-required-file', 'closure-variable'],
            $result->value['coverage']['forwarderForms'],
        );
        self::assertFalse($result->value['coverage']['variableAssignmentsResolved']);
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

    private function workspace(string $fixture = 'DiComposition'): WorkspaceContext
    {
        $workspace = WorkspaceContext::fromRoot(dirname(__DIR__, 3) . '/Fixture/' . $fixture)->value;
        self::assertNotNull($workspace);

        return $workspace;
    }
}
