<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Sql;

use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Sql\SqlReferenceFinder;

final class SqlReferenceFinderTest extends TestCase
{
    public function testAdaptsAttributeAndLegacyReferencesToQuoteInclusiveLocations(): void
    {
        $file = self::fixtureDir() . '/src/Query/PointQueryInterface.php';
        $source = (string) file_get_contents($file);
        $offset = strpos($source, 'point_distance');
        self::assertNotFalse($offset);
        $finder = new SqlReferenceFinder();

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

        $expected = [];
        foreach (['LegacyPointQueryInterface.php', 'PointQueryInterface.php'] as $name) {
            $path = self::fixtureDir() . '/src/Query/' . $name;
            $contents = (string) file_get_contents($path);
            $start = strpos($contents, 'point_distance');
            self::assertNotFalse($start);
            $expected[] = [$path, $start - 1, $start + strlen('point_distance') + 1, true];
        }
        usort($expected, static fn (array $left, array $right): int => $left <=> $right);

        self::assertSame($expected, $locations);
        self::assertFalse($generator->getReturn());
    }

    public function testReturnsEmptyForAnUnresolvedSqlIdAndContinuesTheChain(): void
    {
        $file = self::fixtureDir() . '/src/Query/MissingQueryInterface.php';
        $source = (string) file_get_contents($file);
        $offset = strpos($source, 'missing_query');
        self::assertNotFalse($offset);

        $generator = (new SqlReferenceFinder())->findReferences(
            TextDocumentBuilder::create($source)->uri($file)->language('php')->build(),
            ByteOffset::fromInt($offset),
        );

        self::assertSame([], iterator_to_array($generator));
        self::assertFalse($generator->getReturn());
    }

    public function testReturnsEmptyForANonSqlLocationAndContinuesTheChain(): void
    {
        $source = '<?php $value = "point_distance";';
        $file = self::fixtureDir() . '/src/Query/PointQueryInterface.php';

        $generator = (new SqlReferenceFinder())->findReferences(
            TextDocumentBuilder::create($source)->uri($file)->language('php')->build(),
            ByteOffset::fromInt((int) strpos($source, 'point_distance')),
        );

        self::assertSame([], iterator_to_array($generator));
        self::assertFalse($generator->getReturn());
    }

    public function testDoesNotWalkAboveTheConfiguredLspWorkspace(): void
    {
        $file = self::fixtureDir() . '/src/Query/PointQueryInterface.php';
        $source = (string) file_get_contents($file);
        $offset = strpos($source, 'point_distance');
        self::assertNotFalse($offset);
        $finder = new SqlReferenceFinder(workspaceRoot: self::fixtureDir() . '/src');

        $generator = $finder->findReferences(
            TextDocumentBuilder::create($source)->uri($file)->language('php')->build(),
            ByteOffset::fromInt($offset),
        );

        self::assertSame([], iterator_to_array($generator));
        self::assertFalse($generator->getReturn());
    }

    private static function fixtureDir(): string
    {
        $fixture = realpath(dirname(__DIR__, 2) . '/Fixture/Sql/App1');
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
