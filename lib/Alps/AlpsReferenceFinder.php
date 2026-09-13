<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Alps;

use Generator;
use Phpactor\ReferenceFinder\PotentialLocation;
use Phpactor\ReferenceFinder\ReferenceFinder;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\Location;
use Phpactor\TextDocument\TextDocument;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsDescriptorReferencesQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Util\ProjectLocator;

/**
 * Adapts ALPS descriptor attribute usages to standard Phpactor locations.
 */
final class AlpsReferenceFinder implements ReferenceFinder
{
    public function __construct(
        private AlpsDescriptorAtOffset $descriptorAtOffset = new AlpsDescriptorAtOffset(),
        private AlpsDescriptorReferencesQuery $referencesQuery = new AlpsDescriptorReferencesQuery(),
        private ?string $workspaceRoot = null,
    ) {
    }

    public function findReferences(TextDocument $document, ByteOffset $byteOffset): Generator
    {
        if (!$document->language()->isPhp() || !str_contains($document->__toString(), 'Alps')) {
            return false;
        }

        $origin = ($this->descriptorAtOffset)($document, $byteOffset->toInt());
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
            $origin[1],
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
