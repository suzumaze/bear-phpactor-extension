<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Sql;

use Suzumaze\BearPhpactor\BearSundayExtension;
use Phpactor\Container\PhpactorContainer;
use Phpactor\Extension\Logger\LoggingExtension;
use Phpactor\Extension\ReferenceFinder\ReferenceFinderExtension;
use Phpactor\ReferenceFinder\DefinitionLocator;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocumentBuilder;
use PHPUnit\Framework\TestCase;

/**
 * BearSundayExtension の登録ブロックが、reference_finder.definition_locator
 * タグで SqlDefinitionLocator を連鎖に組み込むことを、実コンテナで検証する。
 */
final class BearSundayExtensionTest extends TestCase
{
    public function testSqlDefinitionLocatorIsRegisteredInChain(): void
    {
        $container = PhpactorContainer::fromExtensions([
            LoggingExtension::class,
            ReferenceFinderExtension::class,
            BearSundayExtension::class,
        ], []);

        $locator = $container->get(ReferenceFinderExtension::SERVICE_DEFINITION_LOCATOR);
        self::assertInstanceOf(DefinitionLocator::class, $locator);

        $path = realpath(__DIR__ . '/../../Fixture/Sql/App1/src/Query/PointQueryInterface.php');
        self::assertNotFalse($path);
        $text = (string) file_get_contents($path);
        $offset = strpos($text, "'point_distance'") + 1;
        self::assertNotFalse($offset);

        $document = TextDocumentBuilder::create($text)
            ->uri($path)
            ->language('php')
            ->build();

        // タグ経由で連鎖に載っていれば、コンテナの連鎖ロケータがSQLへ解決する
        $typeLocations = $locator->locateDefinition($document, ByteOffset::fromInt($offset));

        self::assertSame(1, $typeLocations->count());
        self::assertSame(
            realpath(__DIR__ . '/../../Fixture/Sql/App1/var/db/sql/point_distance.sql'),
            $typeLocations->first()->location()->uri()->path()
        );
    }
}
