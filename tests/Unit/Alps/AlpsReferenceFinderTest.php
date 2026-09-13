<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Alps;

use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Alps\AlpsReferenceFinder;

final class AlpsReferenceFinderTest extends TestCase
{
    public function testAdaptsSameDescriptorUsagesToQuoteInclusiveLocations(): void
    {
        $file = self::fixtureDir() . '/src/AlpsReferences.php';
        $source = (string) file_get_contents($file);
        $offset = strpos($source, 'doDeleteArticle');
        self::assertNotFalse($offset);

        $generator = (new AlpsReferenceFinder())->findReferences(
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

        $first = strpos($source, 'doDeleteArticle');
        self::assertNotFalse($first);
        $second = strpos($source, 'doDeleteArticle', $first + 1);
        self::assertNotFalse($second);
        self::assertSame([
            [$file, $first - 1, $first + strlen('doDeleteArticle') + 1, true],
            [$file, $second - 1, $second + strlen('doDeleteArticle') + 1, true],
        ], $locations);
        self::assertFalse($generator->getReturn());
    }

    public function testMissingDescriptorAndWorkspaceBoundaryReturnEmpty(): void
    {
        $file = self::fixtureDir() . '/src/AlpsReferences.php';
        $source = (string) file_get_contents($file);

        $cases = [
            [str_replace('doDeleteArticle', 'missingDescriptor', $source), null, 'missingDescriptor'],
            [$source, self::fixtureDir() . '/src', 'doDeleteArticle'],
        ];
        foreach ($cases as [$contents, $workspaceRoot, $needle]) {
            $offset = strpos($contents, $needle);
            self::assertNotFalse($offset);
            $finder = new AlpsReferenceFinder(workspaceRoot: $workspaceRoot);
            $generator = $finder->findReferences(
                TextDocumentBuilder::create($contents)->uri($file)->language('php')->build(),
                ByteOffset::fromInt($offset),
            );
            self::assertSame([], iterator_to_array($generator));
            self::assertFalse($generator->getReturn());
        }
    }

    private static function fixtureDir(): string
    {
        $fixture = realpath(dirname(__DIR__, 2) . '/Fixture/Alps/App1');
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
