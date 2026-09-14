<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Parser;
use Phpactor\TextDocument\TextDocumentBuilder;
use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use Suzumaze\BearPhpactor\Router\RouteReferenceAtOffset;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Route\RouteQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\Psr4PhpSourceScanner;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Throwable;

/**
 * Finds static Resource URI and Aura.Router references to one Resource.
 *
 * Equal URI strings are not sufficient evidence: every occurrence is resolved
 * from its own project context and compared by canonical target file. Scanning
 * is bounded to workspace-contained PSR-4 roots and the local route file.
 */
final class ResourceReferencesQuery
{
    private const MAX_ROUTE_BYTES = 1_048_576;

    public function __construct(
        private ResourceQuery $resourceQuery = new ResourceQuery(),
        private RouteQuery $routeQuery = new RouteQuery(),
        private RouteReferenceAtOffset $routeReferenceAtOffset = new RouteReferenceAtOffset(),
        private Parser $parser = new Parser(),
        private Psr4PhpSourceScanner $sourceScanner = new Psr4PhpSourceScanner(),
    ) {
    }

    /** @return SemanticResult<ResourceReferences|null> */
    public function findInWorkspace(
        WorkspaceContext $workspace,
        string $uri,
        ?string $contextPath = null,
    ): SemanticResult {
        $resource = $this->resourceQuery->resolveInWorkspace($workspace, $uri, $contextPath);
        if ($resource->status === SemanticStatus::Ok && $resource->value !== null) {
            return $this->findForResolutionInWorkspace($workspace, $resource->value, $contextPath);
        }
        if ($resource->status !== SemanticStatus::Ambiguous) {
            return SemanticResult::failure($resource->status);
        }

        return SemanticResult::ambiguous(array_map(
            ResourceReferences::unavailable(...),
            $resource->candidates,
        ));
    }

    /**
     * Resolve a Resource class selected by an adapter from a workspace-relative
     * file, then find its references without accepting an arbitrary path.
     *
     * @return SemanticResult<ResourceReferences|null>
     */
    public function findForFileInWorkspace(
        WorkspaceContext $workspace,
        string $resourcePath,
        ?string $contextPath = null,
    ): SemanticResult {
        $targetPath = $workspace->accessPolicy()->resolveExisting($resourcePath);
        if ($targetPath->value === null) {
            return SemanticResult::failure($targetPath->status);
        }
        if (!is_file($targetPath->value->absolute)) {
            return SemanticResult::notFound();
        }
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        /** @var array<string,ResourceResolution> $matches */
        $matches = [];
        foreach ($project->value->resourceClassCandidates() as $candidate) {
            $candidatePath = $workspace->accessPolicy()->inspectExisting($candidate['file']);
            if (
                $candidatePath->value === null
                || $candidatePath->value->absolute !== $targetPath->value->absolute
            ) {
                continue;
            }
            $uri = ResourceUri::fromString($candidate['uri']);
            if ($uri === null) {
                continue;
            }

            $resolution = new ResourceResolution($uri, $targetPath->value->absolute, $candidate['fqn']);
            $key = $uri->uri() . "\0" . $candidate['fqn'];
            $matches[$key] = $resolution;
        }
        ksort($matches, SORT_STRING);
        $matches = array_values($matches);

        if ($matches === []) {
            return SemanticResult::notFound();
        }
        if (count($matches) > 1) {
            return SemanticResult::ambiguous(array_map(
                ResourceReferences::unavailable(...),
                $matches,
            ));
        }

        return $this->findForResolutionInWorkspace($workspace, $matches[0], $contextPath);
    }

    /** @return SemanticResult<ResourceReferences|null> */
    public function findForResolutionInWorkspace(
        WorkspaceContext $workspace,
        ResourceResolution $target,
        ?string $contextPath = null,
    ): SemanticResult {
        $targetPath = $workspace->accessPolicy()->inspectExisting($target->file);
        if ($targetPath->value === null) {
            return SemanticResult::failure($targetPath->status);
        }
        if (!is_file($targetPath->value->absolute)) {
            return SemanticResult::notFound();
        }
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        $references = [];
        foreach ($this->sourceScanner->scan($workspace, $project->value, ['app://', 'page://']) as $source) {
            try {
                $root = $this->parser->parseSourceFile($source->contents, $source->file);
            } catch (Throwable) {
                // Tolerant parsing should not throw. Treat malformed source as empty.
                continue;
            }

            foreach ($root->getDescendantNodes() as $node) {
                if (!$node instanceof StringLiteral) {
                    continue;
                }
                $opening = substr($source->contents, $node->getStartPosition(), 1);
                if ($opening !== "'" && $opening !== '"') {
                    continue;
                }

                $resourceUri = ResourceUri::fromString($node->getStringContentsText());
                if ($resourceUri === null) {
                    continue;
                }
                $sourcePath = $workspace->accessPolicy()->inspectExisting($source->file);
                if ($sourcePath->value === null) {
                    continue;
                }
                $resolved = $this->resourceQuery->resolveInWorkspace(
                    $workspace,
                    $resourceUri->uri(),
                    $sourcePath->value->relative,
                );
                if (
                    $resolved->status !== SemanticStatus::Ok
                    || $resolved->value === null
                    || $resolved->value->file !== $targetPath->value->absolute
                ) {
                    continue;
                }

                $reference = new ResourceReference(
                    ResourceReference::KIND_RESOURCE_URI,
                    $node->getStringContentsText(),
                    $source->file,
                    $node->getStartPosition() + 1,
                    $node->getEndPosition() - 1,
                );
                $references[$this->referenceKey($reference)] = $reference;
            }
        }

        $routeReferences = $this->routeReferences(
            $workspace,
            $project->value->root(),
            $targetPath->value->absolute,
        );
        foreach ($routeReferences as $reference) {
            $references[$this->referenceKey($reference)] = $reference;
        }

        $references = array_values($references);
        usort(
            $references,
            static fn (ResourceReference $left, ResourceReference $right): int => [
                $left->file,
                $left->contentStart,
                $left->contentEnd,
                $left->kind,
                $left->identifier,
            ] <=> [
                $right->file,
                $right->contentStart,
                $right->contentEnd,
                $right->kind,
                $right->identifier,
            ],
        );

        $provenance = [Provenance::savedFile($targetPath->value->relative), Provenance::derived()];
        foreach ($references as $reference) {
            $path = $workspace->accessPolicy()->inspectExisting($reference->file);
            if ($path->value !== null) {
                $provenance[] = Provenance::savedFile(
                    $path->value->relative,
                    $reference->contentStart,
                    $reference->contentEnd,
                );
            }
        }

        return SemanticResult::ok(
            new ResourceReferences(
                new ResourceResolution($target->uri, $targetPath->value->absolute, $target->fqn),
                $references,
            ),
            $provenance,
        );
    }

    /** @return list<ResourceReference> */
    private function routeReferences(
        WorkspaceContext $workspace,
        string $projectRoot,
        string $targetFile,
    ): array {
        $routePath = $workspace->accessPolicy()->inspectExisting($projectRoot . '/aura.route.php');
        if ($routePath->value === null || !is_file($routePath->value->absolute)) {
            return [];
        }

        $source = @file_get_contents(
            $routePath->value->absolute,
            false,
            null,
            0,
            self::MAX_ROUTE_BYTES + 1,
        );
        if ($source === false || strlen($source) > self::MAX_ROUTE_BYTES) {
            return [];
        }
        $document = TextDocumentBuilder::create($source)
            ->uri($routePath->value->absolute)
            ->language('php')
            ->build();

        try {
            $found = $this->routeReferenceAtOffset->references($document);
        } catch (Throwable) {
            return [];
        }

        $references = [];
        foreach ($found as [$start, $routeName, $end]) {
            $route = $this->routeQuery->resolveInWorkspace(
                $workspace,
                $routeName,
                $routePath->value->relative,
            );
            if (
                $route->status !== SemanticStatus::Ok
                || $route->value === null
                || $route->value->resource->file !== $targetFile
            ) {
                continue;
            }
            $references[] = new ResourceReference(
                ResourceReference::KIND_ROUTE,
                $routeName,
                $routePath->value->absolute,
                $start,
                $end,
            );
        }

        return $references;
    }

    private function referenceKey(ResourceReference $reference): string
    {
        return implode("\0", [
            $reference->file,
            (string) $reference->contentStart,
            (string) $reference->contentEnd,
            $reference->kind,
        ]);
    }
}
