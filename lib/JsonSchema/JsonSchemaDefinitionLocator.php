<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\JsonSchema;

use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Util\ProjectLocator;
use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
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
 * with the other three locators), not from the LSP workspace root. String
 * literals are extracted via StringLiteralAtOffset and resolved paths are
 * checked by PathGuard, both shared across the four features. The schema-file
 * resolution itself is delegated to JsonSchemaPathResolver, shared with the
 * body-key completor (BodyPropertyCompletor).
 *
 * The target must exist on disk; otherwise no location is returned.
 */
final class JsonSchemaDefinitionLocator implements DefinitionLocator
{
    public function __construct(
        private StringLiteralAtOffset $stringLiteralAtOffset = new StringLiteralAtOffset(),
        private Parser $parser = new Parser(),
        private JsonSchemaPathResolver $schemaPathResolver = new JsonSchemaPathResolver(),
    ) {
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
        $found = ProjectLocator::locate($uri->path());
        if ($found === null) {
            throw new CouldNotLocateDefinition(
                sprintf('No composer.json with autoload.psr-4 above "%s"', $uri->path())
            );
        }
        $root = $found['root'];

        $rootNode = $this->parser->parseSourceFile($document->__toString());
        $offset = $byteOffset->toInt();

        $schemaPath = $this->schemaPathFromAttribute($document, $offset, $root);

        if ($schemaPath === null) {
            throw new CouldNotLocateDefinition('No JSON Schema reference found at offset');
        }

        // 着地はスキーマの "title" キーの位置 (無ければファイル先頭 (0,0))。
        // ファイル先頭に着地すると「なぜここに来たか」が読めないため。
        $titleOffset = $this->schemaPathResolver->titleKeyOffset($schemaPath);

        return new TypeLocations([
            new TypeLocation(
                TypeFactory::string(),
                Location::fromPathAndOffsets($schemaPath, $titleOffset, $titleOffset),
            ),
        ]);
    }

    /**
     * Resolves the string literal of a #[JsonSchema(...)] attribute under the
     * cursor to a schema file on disk. Null when the cursor is not on such a
     * literal, the file name is not a schema file, or the file does not exist.
     */
    private function schemaPathFromAttribute(TextDocument $document, int $offset, string $root): ?string
    {
        $literal = $this->stringLiteralAtOffset->literal($document, $offset);
        if ($literal === null) {
            return null;
        }

        $argument = null;
        for ($node = $literal; $node instanceof Node; $node = $node->getParent()) {
            if ($argument === null && $node instanceof ArgumentExpression) {
                $argument = $node;
            }
            if ($node instanceof Attribute) {
                if (!$this->schemaPathResolver->isJsonSchemaAttribute($node)) {
                    return null;
                }

                return $this->schemaPathResolver->attributePath(
                    $root,
                    $document->__toString(),
                    $literal,
                    $argument,
                );
            }
        }

        return null;
    }
}
