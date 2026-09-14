<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/**
 * Lists Resource identities without depending on an editor or LSP position.
 */
final class ResourceInventoryQuery
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public function __construct(private ?ResourceInventoryIndex $inventoryIndex = null)
    {
    }

    /**
     * @return SemanticResult<ResourceInventory|null>
     */
    public function listInWorkspace(
        WorkspaceContext $workspace,
        ?string $scheme = null,
        string $prefix = '',
        int $limit = self::DEFAULT_LIMIT,
    ): SemanticResult {
        if (!$this->validFilters($scheme, $prefix, $limit)) {
            return SemanticResult::invalidInput();
        }

        $project = $workspace->project();
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        /** @var array<string,ResourceResolution> $resources */
        $resources = [];
        $candidates = $this->inventoryIndex === null
            ? $project->value->resourceClassCandidates()
            : $this->inventoryIndex->candidates($project->value);
        foreach ($candidates as $candidate) {
            $uri = ResourceUri::fromString($candidate['uri']);
            if ($uri === null || ($scheme !== null && $uri->scheme() !== $scheme)) {
                continue;
            }
            if ($prefix !== '' && !str_starts_with($uri->path(), $prefix)) {
                continue;
            }

            $path = $workspace->accessPolicy()->inspectExisting($candidate['file']);
            if ($path->value === null) {
                return SemanticResult::failure($path->status);
            }

            $resolution = new ResourceResolution($uri, $path->value->absolute, $candidate['fqn']);
            $key = $uri->uri() . "\0" . $path->value->absolute;
            if (!isset($resources[$key]) || $resolution->fqn < $resources[$key]->fqn) {
                $resources[$key] = $resolution;
            }
        }

        $resources = array_values($resources);
        usort(
            $resources,
            static fn (ResourceResolution $left, ResourceResolution $right): int =>
                [$left->uri->uri(), $left->file, $left->fqn]
                <=> [$right->uri->uri(), $right->file, $right->fqn],
        );

        $total = count($resources);

        $selected = array_slice($resources, 0, $limit);
        $composer = $workspace->accessPolicy()->inspectExisting($project->value->root() . '/composer.json');
        if ($composer->value === null) {
            return SemanticResult::failure($composer->status);
        }
        $provenance = [Provenance::savedFile($composer->value->relative), Provenance::derived()];
        foreach ($selected as $resource) {
            $path = $workspace->accessPolicy()->inspectExisting($resource->file);
            if ($path->value !== null) {
                $provenance[] = Provenance::savedFile($path->value->relative);
            }
        }

        return SemanticResult::ok(
            new ResourceInventory($selected, $total, $total > $limit),
            $provenance,
        );
    }

    private function validFilters(?string $scheme, string $prefix, int $limit): bool
    {
        if ($scheme !== null && $scheme !== 'app' && $scheme !== 'page') {
            return false;
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            return false;
        }
        if (
            str_contains($prefix, "\0")
            || str_contains($prefix, '\\')
            || str_contains($prefix, '//')
            || str_starts_with($prefix, '/')
            || str_ends_with($prefix, '/')
        ) {
            return false;
        }
        foreach (explode('/', $prefix) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
