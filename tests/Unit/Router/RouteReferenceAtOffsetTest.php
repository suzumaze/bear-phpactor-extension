<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Router;

use Phpactor\TextDocument\TextDocumentBuilder;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Router\RouteReferenceAtOffset;

final class RouteReferenceAtOffsetTest extends TestCase
{
    public function testRecognizesOnlyFirstArgumentOfKnownRouteCall(): void
    {
        $source = "<?php\n\$map->get('/article', '/articles/{id}');\n";
        $document = TextDocumentBuilder::create($source)
            ->uri('file:///workspace/aura.route.php')
            ->language('php')
            ->build();
        $detector = new RouteReferenceAtOffset();

        self::assertSame(
            [$start = (int) strpos($source, '/article'), '/article', $start + strlen('/article')],
            $detector($document, $start),
        );
        self::assertNull($detector($document, (int) strpos($source, '/articles/{id}')));
    }

    public function testPreservesSupportedQualifiedCallAndRejectsUnknownCall(): void
    {
        $source = "<?php\n\\route('/index', '/');\nattach('/admin', '/admin', fn () => null);\n";
        $document = TextDocumentBuilder::create($source)
            ->uri('file:///workspace/aura.route.php')
            ->language('php')
            ->build();
        $detector = new RouteReferenceAtOffset();

        $routeStart = (int) strpos($source, '/index');
        self::assertSame(
            [$routeStart, '/index', $routeStart + strlen('/index')],
            $detector($document, $routeStart),
        );
        self::assertNull($detector($document, (int) strpos($source, '/admin')));
    }

    public function testRecognizesNamedRouteNameAndRejectsNamedHttpPath(): void
    {
        $source = "<?php\n\$map->get(name: '/index', path: '/actual-index');\n"
            . "\$map->get(path: '/not-a-name');\n";
        $document = TextDocumentBuilder::create($source)
            ->uri('file:///workspace/aura.route.php')
            ->language('php')
            ->build();
        $detector = new RouteReferenceAtOffset();

        $routeStart = (int) strpos($source, '/index');
        self::assertSame(
            [$routeStart, '/index', $routeStart + strlen('/index')],
            $detector($document, $routeStart),
        );
        self::assertNull($detector($document, (int) strpos($source, '/actual-index')));
        self::assertNull($detector($document, (int) strpos($source, '/not-a-name')));
    }

    public function testRejectsQuoteBoundaryAndNonRouteFile(): void
    {
        $source = "<?php\n\$map->route('/index', '/');\n";
        $quote = (int) strpos($source, "'/index'");
        $detector = new RouteReferenceAtOffset();
        $routeDocument = TextDocumentBuilder::create($source)
            ->uri('file:///workspace/aura.route.php')
            ->language('php')
            ->build();
        $otherDocument = TextDocumentBuilder::create($source)
            ->uri('file:///workspace/routes.php')
            ->language('php')
            ->build();

        self::assertNull($detector($routeDocument, $quote));
        self::assertNull($detector($routeDocument, $quote + strlen("'/index'")));
        self::assertNull($detector($otherDocument, $quote + 1));

        $dynamicSource = "<?php\n\$map->route(ROUTE_NAME, '/');\n";
        $dynamicDocument = TextDocumentBuilder::create($dynamicSource)
            ->uri('file:///workspace/aura.route.php')
            ->language('php')
            ->build();
        self::assertNull($detector($dynamicDocument, (int) strpos($dynamicSource, 'ROUTE_NAME')));
    }

    public function testListsOnlyStaticRouteNameReferencesDeterministically(): void
    {
        $source = <<<'PHP'
<?php
$map->route('/article', '/articles/{id}');
$map->get(name: '/named', path: '/named-path');
$map->attach('/prefix', '/prefix', fn () => null);
PHP;
        $document = TextDocumentBuilder::create($source)
            ->uri('file:///workspace/aura.route.php')
            ->language('php')
            ->build();
        $detector = new RouteReferenceAtOffset();

        $article = (int) strpos($source, '/article');
        $named = (int) strpos($source, '/named');
        self::assertSame([
            [$article, '/article', $article + strlen('/article')],
            [$named, '/named', $named + strlen('/named')],
        ], $detector->references($document));
    }
}
