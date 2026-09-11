<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Suzumaze\BearPhpactor\Resource\Model\ImportAppRegistry;
use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;

/**
 * Resolves BEAR resource identities without depending on LSP positions or
 * locations. Transport adapters decide how each semantic status is exposed.
 */
final class ResourceQuery
{
    /**
     * @return SemanticResult<ResourceResolution|null>
     */
    public function resolveString(Project $project, string $uri): SemanticResult
    {
        $resourceUri = ResourceUri::fromString($uri);
        if ($resourceUri === null) {
            return SemanticResult::invalidInput();
        }

        return $this->resolve($project, $resourceUri);
    }

    /**
     * @return SemanticResult<ResourceResolution|null>
     */
    public function resolve(Project $project, ResourceUri $uri): SemanticResult
    {
        if ($this->containsParentSegment($uri)) {
            return SemanticResult::invalidInput();
        }

        if ($uri->host() !== 'self') {
            $target = ImportAppRegistry::forProject($project->root())->resolve($uri);
            if ($target === null) {
                return SemanticResult::notFound();
            }

            return SemanticResult::ok($this->resolution($uri, $target));
        }

        $file = $project->classFile($uri);
        $fqn = $project->classFqn($uri);
        if ($file !== null && $fqn !== null && is_file($file)) {
            return SemanticResult::ok(new ResourceResolution($uri, $file, $fqn));
        }

        $candidates = array_map(
            fn (array $candidate): ResourceResolution => $this->resolution($uri, $candidate),
            $project->classFileCandidates($uri),
        );
        usort(
            $candidates,
            static fn (ResourceResolution $left, ResourceResolution $right): int =>
                [$left->file, $left->fqn] <=> [$right->file, $right->fqn],
        );

        if ($candidates === []) {
            return SemanticResult::notFound();
        }

        if (count($candidates) > 1) {
            return SemanticResult::ambiguous($candidates);
        }

        return SemanticResult::ok($candidates[0]);
    }

    /**
     * @param array{file: string, fqn: string} $target
     */
    private function resolution(ResourceUri $uri, array $target): ResourceResolution
    {
        return new ResourceResolution($uri, $target['file'], $target['fqn']);
    }

    private function containsParentSegment(ResourceUri $uri): bool
    {
        foreach (explode('/', $uri->path()) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }
}
