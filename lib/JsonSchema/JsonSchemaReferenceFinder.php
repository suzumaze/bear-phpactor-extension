<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\JsonSchema;

use Generator;
use Phpactor\ReferenceFinder\PotentialLocation;
use Phpactor\ReferenceFinder\ReferenceFinder;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\Location;
use Phpactor\TextDocument\TextDocument;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaReferencesQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Util\ProjectLocator;

/**
 * Adapts explicit JsonSchema references to standard Phpactor locations.
 */
final class JsonSchemaReferenceFinder implements ReferenceFinder
{
    public function __construct(
        private JsonSchemaReferenceAtOffset $referenceAtOffset = new JsonSchemaReferenceAtOffset(),
        private SchemaReferencesQuery $referencesQuery = new SchemaReferencesQuery(),
        private ?string $workspaceRoot = null,
    ) {
    }

    public function findReferences(TextDocument $document, ByteOffset $byteOffset): Generator
    {
        if (
            !$document->language()->isPhp()
            || !str_contains($document->__toString(), 'JsonSchema')
        ) {
            return false;
        }

        $origin = ($this->referenceAtOffset)($document, $byteOffset->toInt());
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

        $result = $this->referencesQuery->findNamedInWorkspace(
            $workspace->value,
            $origin[1],
            $origin[3],
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
