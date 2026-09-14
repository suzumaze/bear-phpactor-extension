<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\JsonSchema;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaQuery;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Microsoft\PhpParser\Parser;
use Phpactor\ReferenceFinder\DefinitionLocator;
use Phpactor\ReferenceFinder\Exception\CouldNotLocateDefinition;
use Phpactor\ReferenceFinder\Exception\UnsupportedDocument;
use Phpactor\ReferenceFinder\TypeLocation;
use Phpactor\ReferenceFinder\TypeLocations;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\Location;
use Phpactor\TextDocument\TextDocument;
use Phpactor\WorseReflection\Core\TypeFactory;

/**
 * Jumps from a BEAR.Sunday resource to its JSON Schema file.
 *
 * The source of the schema file name, mirroring the reference IntelliJ plugin
 * (idea-php-bearsunday-plugin):
 *
 * 1. The string literal of a #[JsonSchema('user.json')] attribute (the schema:
 *    named argument or the first positional argument). A params: named argument
 *    is a request schema and resolves under var/json_validate instead of
 *    var/json_schema.
 *
 * The class-name convention (a resource class without an attribute resolving to
 * var/json_schema/<kebab-case name>.json) used to live here too, but it moved
 * to JsonSchemaConventionTypeLocator (textDocument/typeDefinition): on a class
 * declaration name, F12 (definition) is expected to stay put in VS Code, and
 * the convention jump was overriding that.
 *
 * The project root comes from the document's location (ProjectLocator, shared
 * with the other four locators), not from the LSP workspace root. String
 * literals are extracted via StringLiteralAtOffset and resolved paths are
 * checked by PathGuard, both shared across the five features. The schema-file
 * resolution itself is delegated to JsonSchemaPathResolver, shared with the
 * body-key completor (BodyPropertyCompletor).
 *
 * The target must exist on disk; otherwise no location is returned.
 */
final class JsonSchemaDefinitionLocator implements DefinitionLocator
{
    private SchemaQuery $schemaQuery;
    private JsonSchemaReferenceAtOffset $referenceAtOffset;

    public function __construct(
        StringLiteralAtOffset $stringLiteralAtOffset = new StringLiteralAtOffset(),
        Parser $parser = new Parser(),
        JsonSchemaPathResolver $schemaPathResolver = new JsonSchemaPathResolver(),
        ?SchemaQuery $schemaQuery = null,
        ?JsonSchemaReferenceAtOffset $referenceAtOffset = null,
    ) {
        // Keep the former parser argument for positional and named constructor compatibility.
        unset($parser);
        $this->schemaQuery = $schemaQuery ?? new SchemaQuery($schemaPathResolver);
        $this->referenceAtOffset = $referenceAtOffset ?? new JsonSchemaReferenceAtOffset($stringLiteralAtOffset);
    }

    public function locateDefinition(TextDocument $document, ByteOffset $byteOffset): TypeLocations
    {
        if (!$document->language()->isPhp()) {
            throw new UnsupportedDocument(sprintf('Language must be php, got "%s"', $document->language()));
        }

        // 入口の安価な事前判定: 属性経由は JsonSchema という名前がドキュメントに
        // 必ず現れる。無ければ構文解析より先に降りる (誤検出は許容、取りこぼしは
        // 無い)。クラス名規約の分は JsonSchemaConventionTypeLocator に移した。
        $text = $document->__toString();
        if (!str_contains($text, JsonSchemaPathResolver::JSON_SCHEMA_ATTRIBUTE)) {
            throw new CouldNotLocateDefinition('No JSON Schema reference found at offset');
        }

        $uri = $document->uri();
        if ($uri === null) {
            throw new CouldNotLocateDefinition('Document has no URI');
        }
        $project = Project::locate($uri->path());
        if ($project === null) {
            throw new CouldNotLocateDefinition(
                sprintf('No composer.json with autoload.psr-4 above "%s"', $uri->path())
            );
        }
        $offset = $byteOffset->toInt();

        $reference = ($this->referenceAtOffset)($document, $offset);

        if ($reference === null) {
            throw new CouldNotLocateDefinition('No JSON Schema reference found at offset');
        }

        $result = $this->schemaQuery->resolveNamed($project, $reference[1], $reference[3]);
        if ($result->status !== SemanticStatus::Ok || $result->value === null || $result->value->file === null) {
            throw new CouldNotLocateDefinition('No JSON Schema reference found at offset');
        }

        $titleOffset = $result->value->titleOffset ?? 0;

        return new TypeLocations([
            new TypeLocation(
                TypeFactory::string(),
                Location::fromPathAndOffsets($result->value->file, $titleOffset, $titleOffset),
            ),
        ]);
    }
}
