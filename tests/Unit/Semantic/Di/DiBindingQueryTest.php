<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Di;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Di\DiBindingFact;
use Suzumaze\BearPhpactor\Semantic\Di\DiBindingInventory;
use Suzumaze\BearPhpactor\Semantic\Di\DiBindingQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

final class DiBindingQueryTest extends TestCase
{
    public function testInventoriesOnlyDirectStaticClassBindingsAndKeepsUnresolvedDeclarations(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->fixture());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = (new DiBindingQuery())->listInWorkspace($workspace->value);

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(DiBindingInventory::class, $result->value);
        self::assertSame(3, $result->value->total);
        self::assertSame(1, $result->value->scannedModules);
        self::assertSame(2, $result->value->unresolved);
        self::assertSame([
            DiBindingFact::STATE_RESOLVED,
            DiBindingFact::STATE_UNRESOLVED,
            DiBindingFact::STATE_UNRESOLVED,
        ], array_column($result->value->items, 'state'));
        self::assertSame(
            'Acme\\DiAop\\Service\\ClockInterface',
            $result->value->items[0]->sourceType,
        );
        self::assertSame('Acme\\DiAop\\Service\\Clock', $result->value->items[0]->targetType);
        self::assertSame('binding_source_not_static', $result->value->items[1]->reason);
        self::assertSame('binding_chain_unsupported', $result->value->items[2]->reason);
        self::assertSame(
            ['src/Module/AppModule.php'],
            array_values(array_unique(array_column($result->value->items, 'path'))),
        );
    }

    public function testFiltersByExactSourceTypeAndValidatesPagination(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->fixture());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);
        $query = new DiBindingQuery();

        $result = $query->listInWorkspace(
            $workspace->value,
            'Acme\\DiAop\\Service\\ClockInterface',
            limit: 1,
        );

        self::assertInstanceOf(DiBindingInventory::class, $result->value);
        self::assertSame(2, $result->value->total);
        self::assertCount(1, $result->value->items);
        self::assertTrue($result->value->truncated);
        self::assertSame(
            SemanticStatus::InvalidInput,
            $query->listInWorkspace($workspace->value, limit: 101)->status,
        );
    }

    private function fixture(): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/DiAop');
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
