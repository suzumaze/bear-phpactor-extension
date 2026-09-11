<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Template;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Template\TemplateQuery;
use Phpactor\ReferenceFinder\DefinitionLocator;
use Phpactor\ReferenceFinder\Exception\CouldNotLocateDefinition;
use Phpactor\ReferenceFinder\TypeLocation;
use Phpactor\ReferenceFinder\TypeLocations;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\Location;
use Phpactor\TextDocument\TextDocument;
use Phpactor\WorseReflection\Core\TypeFactory;

/**
 * Twig/Qiq内の静的なテンプレート名から、BEAR標準配置の実ファイルへ飛ばす。
 */
final class TemplateDefinitionLocator implements DefinitionLocator
{
    private TemplateQuery $query;

    public function __construct(
        private TemplateReferenceScanner $scanner = new TemplateReferenceScanner(),
        TemplatePathResolver $resolver = new TemplatePathResolver(),
        ?TemplateQuery $query = null,
    ) {
        $this->query = $query ?? new TemplateQuery($resolver);
    }

    public function locateDefinition(TextDocument $document, ByteOffset $byteOffset): TypeLocations
    {
        $reference = null;
        foreach ($this->references($document) as $candidate) {
            if ($candidate->contains($byteOffset->toInt())) {
                $reference = $candidate;
                break;
            }
        }
        if ($reference === null) {
            throw new CouldNotLocateDefinition('No static Twig/Qiq template reference under the cursor');
        }

        $uri = $document->uri();
        if ($uri === null) {
            throw new CouldNotLocateDefinition('Document has no URI');
        }

        $project = Project::locate($uri->path());
        if ($project === null) {
            throw new CouldNotLocateDefinition('No composer.json with autoload.psr-4 found above the document');
        }

        $result = $this->query->resolve($project, $reference->engine, $reference->name, $uri->path());
        if ($result->status !== SemanticStatus::Ok || $result->value === null) {
            throw new CouldNotLocateDefinition(sprintf('Template could not be resolved: %s', $reference->name));
        }

        return new TypeLocations([
            new TypeLocation(
                TypeFactory::stringLiteral($reference->name),
                Location::fromPathAndOffsets($result->value->file, 0, 0),
            ),
        ]);
    }

    /** @return list<TemplateReference> */
    public function references(TextDocument $document): array
    {
        return $this->scanner->references($document);
    }
}
