<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\JsonSchema;

use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaReferenceFinder;

final class JsonSchemaReferenceFinderTest extends TestCase
{
    public function testAdaptsSameSchemaReferencesToQuoteInclusiveLocations(): void
    {
        $file = self::fixtureDir() . '/src/SchemaReferences.php';
        $source = (string) file_get_contents($file);
        $offset = strpos($source, 'user.json');
        self::assertNotFalse($offset);
        $finder = new JsonSchemaReferenceFinder();

        $generator = $finder->findReferences(
            TextDocumentBuilder::create($source)->uri($file)->language('php')->build(),
            ByteOffset::fromInt($offset),
        );
        $locations = array_map(
            static fn ($potential): array => [
                $potential->location()->uri()->path(),
                $potential->location()->range()->start()->toInt(),
                $potential->location()->range()->end()->toInt(),
                $potential->isSurely(),
            ],
            iterator_to_array($generator),
        );

        $first = strpos($source, 'user.json');
        self::assertNotFalse($first);
        $second = strpos($source, 'user.json', $first + 1);
        self::assertNotFalse($second);
        self::assertSame([
            [$file, $first - 1, $first + strlen('user.json') + 1, true],
            [$file, $second - 1, $second + strlen('user.json') + 1, true],
        ], $locations);
        self::assertFalse($generator->getReturn());
    }

    public function testMissingSchemaReturnsEmpty(): void
    {
        $file = self::fixtureDir() . '/src/SchemaReferences.php';
        $source = str_replace("'user.json'", "'missing.json'", (string) file_get_contents($file));
        $offset = strpos($source, 'missing.json');
        self::assertNotFalse($offset);

        $generator = (new JsonSchemaReferenceFinder())->findReferences(
            TextDocumentBuilder::create($source)->uri($file)->language('php')->build(),
            ByteOffset::fromInt($offset),
        );

        self::assertSame([], iterator_to_array($generator));
        self::assertFalse($generator->getReturn());
    }

    public function testDoesNotWalkAboveTheConfiguredLspWorkspace(): void
    {
        $file = self::fixtureDir() . '/src/SchemaReferences.php';
        $source = (string) file_get_contents($file);
        $offset = strpos($source, 'user.json');
        self::assertNotFalse($offset);
        $finder = new JsonSchemaReferenceFinder(workspaceRoot: self::fixtureDir() . '/src');

        $generator = $finder->findReferences(
            TextDocumentBuilder::create($source)->uri($file)->language('php')->build(),
            ByteOffset::fromInt($offset),
        );

        self::assertSame([], iterator_to_array($generator));
        self::assertFalse($generator->getReturn());
    }

    private static function fixtureDir(): string
    {
        $fixture = realpath(dirname(__DIR__, 2) . '/Fixture/JsonSchema/basic');
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
