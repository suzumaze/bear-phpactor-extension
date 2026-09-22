<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Project;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Project\ContractCoverage;
use Suzumaze\BearPhpactor\Semantic\Project\ContractCoverageQuery;
use Suzumaze\BearPhpactor\Semantic\Project\ContractCoverageSurface;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

final class ContractCoverageQueryTest extends TestCase
{
    public function testReportsAvailableAbsentDynamicAndNotApplicableContractSurfaces(): void
    {
        $result = (new ContractCoverageQuery())->inspectInWorkspace($this->workspace());

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ContractCoverage::class, $result->value);
        self::assertSame(4, $result->value->total);
        self::assertSame(4, $result->value->matchingTotal);
        self::assertSame(0, $result->value->offset);
        self::assertFalse($result->value->truncated);
        self::assertFalse($result->value->gapsOnly);
        self::assertSame(1, $result->value->scannedResources);
        self::assertSame(1, $result->value->analyzedResources);
        self::assertFalse($result->value->resourceScanTruncated);

        $items = [];
        foreach ($result->value->items as $item) {
            $items[$item->method] = $item;
        }

        self::assertSame(
            ContractCoverageSurface::STATE_NOT_APPLICABLE,
            $items['onGet']->requestSchema->state,
        );
        self::assertSame(ContractCoverageSurface::STATE_AVAILABLE, $items['onGet']->responseSchema->state);
        self::assertSame('getUser', $items['onGet']->alps->subject);
        self::assertTrue($items['onGet']->covered());

        self::assertSame(ContractCoverageSurface::STATE_AVAILABLE, $items['onPost']->requestSchema->state);
        self::assertSame('request:user-params.json', $items['onPost']->requestSchema->subject);
        self::assertTrue($items['onPost']->covered());

        self::assertSame(ContractCoverageSurface::STATE_DYNAMIC, $items['onPatch']->requestSchema->state);
        self::assertSame(ContractCoverageSurface::STATE_DYNAMIC, $items['onPatch']->alps->state);
        self::assertSame(
            ['requestSchema', 'alps'],
            $items['onPatch']->gaps,
        );

        self::assertSame(ContractCoverageSurface::STATE_UNRESOLVED, $items['onDelete']->requestSchema->state);
        self::assertSame(ContractCoverageSurface::STATE_UNRESOLVED, $items['onDelete']->responseSchema->state);
        self::assertSame(ContractCoverageSurface::STATE_UNRESOLVED, $items['onDelete']->alps->state);
        self::assertSame(SemanticStatus::NotFound, $items['onDelete']->alps->status);
        self::assertSame(['requestSchema', 'responseSchema', 'alps'], $items['onDelete']->gaps);

        self::assertSame(4, $result->value->summary['methods']);
        self::assertSame(2, $result->value->summary['coveredMethods']);
        self::assertSame(4, $result->value->summary['schemes']['app']['methods']);
        self::assertSame(0, $result->value->summary['schemes']['page']['methods']);
        self::assertSame(1, $result->value->summary['surfaces']['requestSchema']['not_applicable']);
        self::assertSame(1, $result->value->summary['surfaces']['requestSchema']['dynamic']);
        self::assertSame(3, $result->value->summary['surfaces']['responseSchema']['available']);
        self::assertSame(1, $result->value->summary['surfaces']['alps']['unresolved']);
        $provenancePaths = array_values(array_filter(array_map(
            static fn ($evidence): ?string => $evidence->path,
            $result->provenance,
        )));
        self::assertContains('src/Resource/App/User.php', $provenancePaths);
        self::assertContains('var/alps/profile.json', $provenancePaths);
        self::assertContains('var/json_schema/user.json', $provenancePaths);
        self::assertContains('var/json_validate/user-params.json', $provenancePaths);
        self::assertStringNotContainsString(
            dirname(__DIR__, 3),
            json_encode($result->value, JSON_THROW_ON_ERROR),
        );
    }

    public function testReportsAbsentOptionalContractsAsCoverageGapsRatherThanQueryFailure(): void
    {
        $root = realpath(dirname(__DIR__, 3) . '/Fixture/Resource');
        self::assertNotFalse($root);
        $workspace = WorkspaceContext::fromRoot($root);
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = (new ContractCoverageQuery())->inspectInWorkspace($workspace->value);

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ContractCoverage::class, $result->value);
        $user = array_values(array_filter(
            $result->value->items,
            static fn ($item): bool => $item->uri === 'app://self/user' && $item->method === 'onGet',
        ));
        self::assertCount(1, $user);
        self::assertSame(ContractCoverageSurface::STATE_NOT_APPLICABLE, $user[0]->requestSchema->state);
        self::assertSame(ContractCoverageSurface::STATE_ABSENT, $user[0]->responseSchema->state);
        self::assertSame(ContractCoverageSurface::STATE_ABSENT, $user[0]->alps->state);
        self::assertSame(['responseSchema', 'alps'], $user[0]->gaps);
    }

    public function testPaginatesItemsAndCanSelectOnlyAdoptionGaps(): void
    {
        $query = new ContractCoverageQuery();
        $first = $query->inspectInWorkspace($this->workspace(), limit: 1);
        $second = $query->inspectInWorkspace($this->workspace(), limit: 1, offset: 1);
        $gaps = $query->inspectInWorkspace($this->workspace(), limit: 1, offset: 1, gapsOnly: true);

        self::assertSame(SemanticStatus::Ok, $first->status);
        self::assertInstanceOf(ContractCoverage::class, $first->value);
        self::assertInstanceOf(ContractCoverage::class, $second->value);
        self::assertInstanceOf(ContractCoverage::class, $gaps->value);
        self::assertCount(1, $first->value->items);
        self::assertCount(1, $second->value->items);
        self::assertNotSame($first->value->items[0]->method, $second->value->items[0]->method);
        self::assertSame(0, $first->value->offset);
        self::assertSame(1, $second->value->offset);
        self::assertSame(4, $first->value->total);
        self::assertSame(4, $first->value->matchingTotal);
        self::assertTrue($first->value->truncated);
        self::assertSame(4, $gaps->value->total);
        self::assertSame(2, $gaps->value->matchingTotal);
        self::assertSame(1, $gaps->value->offset);
        self::assertTrue($gaps->value->gapsOnly);
        self::assertFalse($gaps->value->truncated);
        self::assertFalse($gaps->value->items[0]->covered());
        self::assertSame(4, $gaps->value->summary['methods']);
    }

    public function testFiltersByUriSchemeWithoutChangingTheWholeProjectSummary(): void
    {
        $root = realpath(dirname(__DIR__, 3) . '/Fixture/Resource');
        self::assertNotFalse($root);
        $workspace = WorkspaceContext::fromRoot($root);
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);
        $query = new ContractCoverageQuery();
        $all = $query->inspectInWorkspace($workspace->value);
        $pages = $query->inspectInWorkspace($workspace->value, scheme: 'page');
        $apps = $query->inspectInWorkspace($workspace->value, scheme: 'app');

        self::assertInstanceOf(ContractCoverage::class, $all->value);
        self::assertInstanceOf(ContractCoverage::class, $pages->value);
        self::assertInstanceOf(ContractCoverage::class, $apps->value);
        self::assertNull($all->value->scheme);
        self::assertSame('page', $pages->value->scheme);
        self::assertSame('app', $apps->value->scheme);
        self::assertSame($all->value->total, $pages->value->total);
        self::assertSame($all->value->total, $apps->value->total);
        self::assertSame($all->value->total, $pages->value->matchingTotal + $apps->value->matchingTotal);
        self::assertSame($all->value->summary, $pages->value->summary);
        self::assertSame($all->value->summary, $apps->value->summary);
        self::assertGreaterThan(0, $pages->value->matchingTotal);
        self::assertGreaterThan(0, $apps->value->matchingTotal);
        self::assertSame(
            $pages->value->matchingTotal,
            $pages->value->summary['schemes']['page']['methods'],
        );
        foreach ($pages->value->items as $item) {
            self::assertStringStartsWith('page://', $item->uri);
        }
        foreach ($apps->value->items as $item) {
            self::assertStringStartsWith('app://', $item->uri);
        }
    }

    public function testRejectsAnUnboundedLimitAndNegativeOffset(): void
    {
        $query = new ContractCoverageQuery();

        self::assertSame(
            SemanticStatus::InvalidInput,
            $query->inspectInWorkspace($this->workspace(), limit: ContractCoverageQuery::MAX_LIMIT + 1)->status,
        );
        self::assertSame(
            SemanticStatus::InvalidInput,
            $query->inspectInWorkspace($this->workspace(), offset: -1)->status,
        );
        self::assertSame(
            SemanticStatus::InvalidInput,
            $query->inspectInWorkspace($this->workspace(), scheme: 'html')->status,
        );
    }

    private function workspace(): WorkspaceContext
    {
        $root = realpath(dirname(__DIR__, 3) . '/Fixture/Contract');
        self::assertNotFalse($root);
        $result = WorkspaceContext::fromRoot($root);
        self::assertInstanceOf(WorkspaceContext::class, $result->value);

        return $result->value;
    }
}
