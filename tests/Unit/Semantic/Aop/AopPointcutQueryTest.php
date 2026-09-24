<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Aop;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Aop\AopPointcutFact;
use Suzumaze\BearPhpactor\Semantic\Aop\AopPointcutInventory;
use Suzumaze\BearPhpactor\Semantic\Aop\AopPointcutQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

final class AopPointcutQueryTest extends TestCase
{
    public function testInventoriesStaticMatcherTreesAndReasonedUnreadablePointcuts(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->fixture());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = (new AopPointcutQuery())->listInWorkspace($workspace->value);

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(AopPointcutInventory::class, $result->value);
        self::assertSame(3, $result->value->total);
        self::assertSame(1, $result->value->scannedModules);
        self::assertSame(1, $result->value->unresolved);
        self::assertSame('any', $result->value->items[0]->classMatcher['kind']);
        self::assertSame('logical_or', $result->value->items[0]->methodMatcher['kind']);
        self::assertSame(
            ['Acme\\DiAop\\Interceptor\\AuditInterceptor'],
            $result->value->items[0]->interceptors,
        );
        self::assertTrue($result->value->items[1]->priority);
        self::assertSame('subclasses_of', $result->value->items[1]->classMatcher['kind']);
        self::assertSame('logical_not', $result->value->items[1]->methodMatcher['kind']);
        self::assertSame(AopPointcutFact::STATE_UNRESOLVED, $result->value->items[2]->state);
        self::assertSame(
            ['class_matcher_unreadable', 'interceptors_unreadable'],
            $result->value->items[2]->reasons,
        );
    }

    public function testFiltersByStaticallyNamedInterceptorAndRejectsInvalidLimit(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->fixture());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);
        $query = new AopPointcutQuery();

        $result = $query->listInWorkspace(
            $workspace->value,
            'Acme\\DiAop\\Interceptor\\TraceInterceptor',
        );

        self::assertInstanceOf(AopPointcutInventory::class, $result->value);
        self::assertSame(1, $result->value->total);
        self::assertTrue($result->value->items[0]->priority);
        self::assertSame(
            SemanticStatus::InvalidInput,
            $query->listInWorkspace($workspace->value, limit: 0)->status,
        );
    }

    private function fixture(): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/DiAop');
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
