<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Sql;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;
use Suzumaze\BearPhpactor\Semantic\Sql\SqlQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

use function Amp\Promise\wait;

final class SqlSearchCoverageTest extends TestCase
{
    public function testWireResultDistinguishesCompleteAbsenceFromSuggestions(): void
    {
        $handler = new SemanticQueryHandler($this->root());
        foreach (['findRecord' => 2, 'missing' => 0] as $id => $count) {
            $result = wait($handler->resolveSql($id));
            self::assertSame('not_found', $result['status']);
            self::assertNull($result['data']);
            self::assertTrue($result['coverage']['suggestionScan']['complete']);
            self::assertSame($count, $result['coverage']['suggestionScan']['candidateCount']);
            self::assertFalse($result['coverage']['suggestionScan']['scanTruncated']);
            self::assertFalse($result['coverage']['suggestionScan']['candidatesTruncated']);
        }
    }

    public function testScanAndCandidateLimitsAreReportedIndependently(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->root())->value;
        self::assertNotNull($workspace);
        $bounded = (new SqlQuery(maxScanEntries: 1))->resolveInWorkspace($workspace, 'missing');
        self::assertSame([], $bounded->candidates);
        self::assertTrue($bounded->coverage['suggestionScan']['scanTruncated']);
        self::assertFalse($bounded->coverage['suggestionScan']['complete']);
        self::assertSame(1, $bounded->coverage['suggestionScan']['scannedEntries']);
        $limited = (new SqlQuery(maxCandidates: 1))->resolveInWorkspace($workspace, 'findRecord');
        self::assertCount(1, $limited->candidates);
        self::assertSame(2, $limited->coverage['suggestionScan']['candidateCount']);
        self::assertTrue($limited->coverage['suggestionScan']['candidatesTruncated']);
        self::assertFalse($limited->coverage['suggestionScan']['scanTruncated']);
        self::assertFalse($limited->coverage['suggestionScan']['complete']);
        self::assertSame($limited->coverage, $limited->withProvenance([])->coverage);
        $exactBoundary = (new SqlQuery(maxScanEntries: 4, maxCandidates: 2))
            ->resolveInWorkspace($workspace, 'findRecord');
        self::assertTrue($exactBoundary->coverage['suggestionScan']['complete']);
    }

    private function root(): string
    {
        return dirname(__DIR__, 3) . '/Fixture/SqlScan';
    }
}
