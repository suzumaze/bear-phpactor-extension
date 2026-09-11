<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Sql;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Sql\SqlQuery;
use Suzumaze\BearPhpactor\Semantic\Sql\SqlResolution;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use PHPUnit\Framework\TestCase;

final class SqlQueryTest extends TestCase
{
    private Project $project;
    private SqlQuery $query;

    protected function setUp(): void
    {
        $project = Project::fromRoot(self::fixtureDir());
        self::assertNotNull($project);
        $this->project = $project;
        $this->query = new SqlQuery();
    }

    public function testResolvesSqlIdWithoutLspContext(): void
    {
        $result = $this->query->resolve($this->project, 'point_distance');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(SqlResolution::class, $result->value);
        self::assertSame('point_distance', $result->value->queryId);
        self::assertSame(self::fixtureDir() . '/var/db/sql/point_distance.sql', $result->value->file);
    }

    public function testDistinguishesInvalidAndMissingIds(): void
    {
        foreach (['', '../escape', 'group/../escape', '/absolute', 'windows\\escape', "nul\0byte"] as $id) {
            self::assertSame(SemanticStatus::InvalidInput, $this->query->resolve($this->project, $id)->status);
        }

        self::assertSame(
            SemanticStatus::NotFound,
            $this->query->resolve($this->project, 'missing_query')->status,
        );
    }

    public function testDoesNotMistakeTwoDotsInsideFilenameForTraversal(): void
    {
        $result = $this->query->resolve($this->project, 'report..latest');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(SqlResolution::class, $result->value);
        self::assertSame(self::fixtureDir() . '/var/db/sql/report..latest.sql', $result->value->file);
    }

    public function testResolvesThroughExplicitHeadlessWorkspace(): void
    {
        $workspace = WorkspaceContext::fromRoot(self::fixtureDir());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = $this->query->resolveInWorkspace(
            $workspace->value,
            'findFoo',
            'src/Query/FullyQualifiedDbQueryInterface.php',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(SqlResolution::class, $result->value);
        self::assertSame(self::fixtureDir() . '/var/db/sql/findFoo.sql', $result->value->file);
    }

    private static function fixtureDir(): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/Sql/App1');
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
