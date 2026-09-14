<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Template;

use Generator;
use Phpactor\ReferenceFinder\PotentialLocation;
use Phpactor\ReferenceFinder\ReferenceFinder;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\Location;
use Phpactor\TextDocument\TextDocument;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Template\TemplateReferencesQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Util\ProjectLocator;

/**
 * Adapts static Twig/Qiq references to standard Phpactor locations.
 */
final class TemplateReferenceFinder implements ReferenceFinder
{
    public function __construct(
        private TemplateReferenceScanner $referenceScanner = new TemplateReferenceScanner(),
        private TemplateReferencesQuery $referencesQuery = new TemplateReferencesQuery(),
        private ?string $workspaceRoot = null,
    ) {
    }

    public function findReferences(TextDocument $document, ByteOffset $byteOffset): Generator
    {
        $origin = null;
        foreach ($this->referenceScanner->references($document) as $candidate) {
            if ($candidate->contains($byteOffset->toInt())) {
                $origin = $candidate;
                break;
            }
        }
        if ($origin === null) {
            return false;
        }

        $uri = $document->uri();
        if ($uri === null || $uri->scheme() !== 'file') {
            return false;
        }
        $workspaceRoot = $this->workspaceRoot;
        if ($workspaceRoot === null) {
            $project = ProjectLocator::locate($uri->path());
            if ($project === null) {
                return false;
            }
            $workspaceRoot = $project['root'];
        }
        $workspace = WorkspaceContext::fromRoot($workspaceRoot);
        if ($workspace->value === null) {
            return false;
        }
        $context = $workspace->value->accessPolicy()->inspectExisting($uri->path());
        if ($context->value === null) {
            return false;
        }

        $result = $this->referencesQuery->findInWorkspace(
            $workspace->value,
            $origin->engine,
            $origin->name,
            $context->value->relative,
        );
        if ($result->status !== SemanticStatus::Ok || $result->value === null) {
            return false;
        }

        foreach ($result->value->references as $reference) {
            yield PotentialLocation::surely(Location::fromPathAndOffsets(
                $reference->sourceFile,
                $reference->contentStart - 1,
                $reference->contentEnd + 1,
            ));
        }

        return false;
    }
}
