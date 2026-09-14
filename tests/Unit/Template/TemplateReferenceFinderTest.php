<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Template;

use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Template\TemplateReferenceFinder;

final class TemplateReferenceFinderTest extends TestCase
{
    public function testAdaptsTwigReferencesToQuoteInclusiveLocations(): void
    {
        $file = self::fixtureDir() . '/src/Resource/Page/TwigReferences.html.twig';
        $source = (string) file_get_contents($file);
        $offset = strpos($source, 'element/component/card.html.twig');
        self::assertNotFalse($offset);

        $generator = (new TemplateReferenceFinder())->findReferences(
            TextDocumentBuilder::create($source)->uri($file)->language('twig')->build(),
            ByteOffset::fromInt($offset),
        );
        $locations = array_map(
            static fn ($potential): string => $potential->location()->uri()->path(),
            iterator_to_array($generator),
        );

        self::assertSame([
            self::fixtureDir() . '/src/Resource/Page/TwigReferences.html.twig',
            self::fixtureDir() . '/src/Resource/Page/TwigReferencesSecond.html.twig',
        ], $locations);
        self::assertFalse($generator->getReturn());
    }

    public function testWorkspaceBoundaryReturnsEmpty(): void
    {
        $file = self::fixtureDir() . '/src/Resource/Page/TwigReferences.html.twig';
        $source = (string) file_get_contents($file);
        $offset = strpos($source, 'element/component/card.html.twig');
        self::assertNotFalse($offset);
        $finder = new TemplateReferenceFinder(workspaceRoot: self::fixtureDir() . '/src');

        $generator = $finder->findReferences(
            TextDocumentBuilder::create($source)->uri($file)->language('twig')->build(),
            ByteOffset::fromInt($offset),
        );

        self::assertSame([], iterator_to_array($generator));
        self::assertFalse($generator->getReturn());
    }

    private static function fixtureDir(): string
    {
        $fixture = realpath(dirname(__DIR__, 2) . '/Fixture/Template');
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
