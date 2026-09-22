<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Project;

use Suzumaze\BearPhpactor\Semantic\Contract\ContractComparison;
use Suzumaze\BearPhpactor\Semantic\Contract\ContractComparisonQuery;
use Suzumaze\BearPhpactor\Semantic\Contract\ContractSurface;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFacts;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceInventoryQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceMethodFact;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/**
 * Reports contract adoption without treating optional artifacts as errors.
 */
final class ContractCoverageQuery
{
    public const DEFAULT_LIMIT = 100;

    /** Count cap; an approximate serialized-byte budget may return fewer items. */
    public const MAX_LIMIT = 100;

    /** @var list<string> */
    private const SURFACES = [
        'requestSchema',
        'responseSchema',
        'alps',
    ];

    /** @var list<string> */
    private const STATES = [
        ContractCoverageSurface::STATE_AVAILABLE,
        ContractCoverageSurface::STATE_ABSENT,
        ContractCoverageSurface::STATE_DYNAMIC,
        ContractCoverageSurface::STATE_UNRESOLVED,
        ContractCoverageSurface::STATE_NOT_APPLICABLE,
    ];

    public function __construct(
        private ResourceInventoryQuery $inventoryQuery = new ResourceInventoryQuery(),
        private ResourceFactsQuery $resourceFactsQuery = new ResourceFactsQuery(),
        private ContractComparisonQuery $comparisonQuery = new ContractComparisonQuery(),
    ) {
    }

    /** @return SemanticResult<ContractCoverage|null> */
    public function inspectInWorkspace(
        WorkspaceContext $workspace,
        ?string $contextPath = null,
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0,
        bool $gapsOnly = false,
        ?string $scheme = null,
    ): SemanticResult {
        if (
            $limit < 1
            || $limit > self::MAX_LIMIT
            || $offset < 0
            || ($scheme !== null && !in_array($scheme, ['app', 'page'], true))
        ) {
            return SemanticResult::invalidInput();
        }

        $inventory = $this->inventoryQuery->allInWorkspace(
            $workspace,
            contextPath: $contextPath,
        );
        if ($inventory->value === null) {
            return SemanticResult::failure($inventory->status, $inventory->error, $inventory->provenance);
        }

        /** @var list<array{item:ContractCoverageItem,provenance:list<Provenance>}> $records */
        $records = [];
        $analyzedResources = 0;
        foreach ($inventory->value->resources as $resource) {
            $path = $workspace->accessPolicy()->inspectExisting($resource->file);
            if ($path->value === null) {
                continue;
            }
            $facts = $this->resourceFactsQuery->describeResolutionInWorkspace($workspace, $resource);
            if (!$facts->value instanceof ResourceFacts) {
                continue;
            }
            ++$analyzedResources;
            foreach ($facts->value->methods as $method) {
                $record = $this->coverageForMethod(
                    $workspace,
                    $facts->value,
                    $method,
                    $path->value->relative,
                );
                if ($record !== null) {
                    $record['provenance'] = [...$facts->provenance, ...$record['provenance']];
                    $records[] = $record;
                }
            }
        }

        usort(
            $records,
            static fn (array $left, array $right): int => [
                $left['item']->uri,
                $left['item']->method,
                $left['item']->path,
            ] <=> [
                $right['item']->uri,
                $right['item']->method,
                $right['item']->path,
            ],
        );

        $items = array_map(static fn (array $record): ContractCoverageItem => $record['item'], $records);
        $total = count($items);
        $schemeRecords = $scheme === null
            ? $records
            : array_values(array_filter(
                $records,
                static fn (array $record): bool => str_starts_with($record['item']->uri, $scheme . '://'),
            ));
        $matchingRecords = $gapsOnly
            ? array_values(array_filter(
                $schemeRecords,
                static fn (array $record): bool => !$record['item']->covered(),
            ))
            : $schemeRecords;
        $matchingTotal = count($matchingRecords);
        $selectedRecords = ProjectReportPage::slice(
            $matchingRecords,
            $offset,
            $limit,
            static fn (array $record): int => ProjectReportPage::serializedBytes($record),
        );
        $selected = array_map(
            static fn (array $record): ContractCoverageItem => $record['item'],
            $selectedRecords,
        );
        $provenance = [Provenance::derived()];
        $project = $workspace->project($contextPath);
        if ($project->value !== null) {
            $composer = $workspace->accessPolicy()->inspectExisting($project->value->root() . '/composer.json');
            if ($composer->value !== null) {
                $provenance[] = Provenance::savedFile($composer->value->relative);
            }
        }
        foreach ($selectedRecords as $record) {
            array_push($provenance, ...$record['provenance']);
        }

        return SemanticResult::ok(
            new ContractCoverage(
                $selected,
                $total,
                $matchingTotal,
                $offset,
                $offset + count($selected) < $matchingTotal,
                $gapsOnly,
                $scheme,
                count($inventory->value->resources),
                $analyzedResources,
                $inventory->value->truncated,
                $this->summary($items),
            ),
            $provenance,
        );
    }

    /** @return array{item:ContractCoverageItem,provenance:list<Provenance>}|null */
    private function coverageForMethod(
        WorkspaceContext $workspace,
        ResourceFacts $facts,
        ResourceMethodFact $method,
        string $path,
    ): ?array {
        $request = $this->comparisonQuery->compareFactsInWorkspace(
            $workspace,
            $facts,
            $method->name,
            SchemaQuery::KIND_REQUEST,
        );
        $response = $this->comparisonQuery->compareFactsInWorkspace(
            $workspace,
            $facts,
            $method->name,
            SchemaQuery::KIND_RESPONSE,
        );
        if (
            !$request->value instanceof ContractComparison
            || !$response->value instanceof ContractComparison
        ) {
            return null;
        }

        $requestSchema = $this->surface($request->value, 'schema');
        $responseSchema = $this->surface($response->value, 'schema');
        $alps = $this->surface($request->value, 'alps');
        if ($requestSchema === null || $responseSchema === null || $alps === null) {
            return null;
        }

        $surfaces = [
            'requestSchema' => $this->coverageSurface(
                $requestSchema,
                $method->parameters === [],
            ),
            'responseSchema' => $this->coverageSurface($responseSchema),
            'alps' => $this->coverageSurface($alps),
        ];
        $gaps = [];
        foreach ($surfaces as $name => $surface) {
            if (
                !in_array(
                    $surface->state,
                    [
                        ContractCoverageSurface::STATE_AVAILABLE,
                        ContractCoverageSurface::STATE_NOT_APPLICABLE,
                    ],
                    true,
                )
            ) {
                $gaps[] = $name;
            }
        }

        return [
            'item' => new ContractCoverageItem(
                $facts->resource->uri->uri(),
                $method->name,
                $path,
                $surfaces['requestSchema'],
                $surfaces['responseSchema'],
                $surfaces['alps'],
                $gaps,
            ),
            'provenance' => [...$request->provenance, ...$response->provenance],
        ];
    }

    private function surface(ContractComparison $comparison, string $source): ?ContractSurface
    {
        foreach ($comparison->surfaces as $surface) {
            if ($surface->source === $source) {
                return $surface;
            }
        }

        return null;
    }

    private function coverageSurface(
        ContractSurface $surface,
        bool $notApplicableWhenAbsent = false,
    ): ContractCoverageSurface {
        if ($surface->status === SemanticStatus::Ok) {
            $state = ContractCoverageSurface::STATE_AVAILABLE;
        } elseif (
            $surface->status === SemanticStatus::Unsupported
            && $surface->subject !== null
            && str_contains($surface->subject, 'dynamic')
        ) {
            $state = ContractCoverageSurface::STATE_DYNAMIC;
        } elseif ($this->isAbsent($surface)) {
            $state = $notApplicableWhenAbsent
                ? ContractCoverageSurface::STATE_NOT_APPLICABLE
                : ContractCoverageSurface::STATE_ABSENT;
        } else {
            $state = ContractCoverageSurface::STATE_UNRESOLVED;
        }

        return new ContractCoverageSurface($state, $surface->status, $surface->subject);
    }

    private function isAbsent(ContractSurface $surface): bool
    {
        if ($surface->source === 'alps') {
            return $surface->status === SemanticStatus::NotFound && $surface->subject === null;
        }
        if ($surface->source !== 'schema') {
            return false;
        }

        return ($surface->status === SemanticStatus::Unsupported && $surface->subject === 'request')
            || ($surface->status === SemanticStatus::NotFound && $surface->subject === 'response:convention');
    }

    /**
     * @param list<ContractCoverageItem> $items
     * @return array{methods:int,coveredMethods:int,surfaces:array<string,array<string,int>>,schemes:array<string,array{methods:int,coveredMethods:int,surfaces:array<string,array<string,int>>}>}
     */
    private function summary(array $items): array
    {
        $counts = $this->emptySurfaceCounts();
        $schemes = [
            'app' => ['methods' => 0, 'coveredMethods' => 0, 'surfaces' => $this->emptySurfaceCounts()],
            'page' => ['methods' => 0, 'coveredMethods' => 0, 'surfaces' => $this->emptySurfaceCounts()],
        ];
        $coveredMethods = 0;
        foreach ($items as $item) {
            $scheme = str_starts_with($item->uri, 'page://') ? 'page' : 'app';
            ++$schemes[$scheme]['methods'];
            if ($item->covered()) {
                ++$coveredMethods;
                ++$schemes[$scheme]['coveredMethods'];
            }
            foreach (self::SURFACES as $surface) {
                ++$counts[$surface][$item->{$surface}->state];
                ++$schemes[$scheme]['surfaces'][$surface][$item->{$surface}->state];
            }
        }

        return [
            'methods' => count($items),
            'coveredMethods' => $coveredMethods,
            'surfaces' => $counts,
            'schemes' => $schemes,
        ];
    }

    /** @return array<string,array<string,int>> */
    private function emptySurfaceCounts(): array
    {
        $counts = [];
        foreach (self::SURFACES as $surface) {
            $counts[$surface] = array_fill_keys(self::STATES, 0);
        }

        return $counts;
    }
}
