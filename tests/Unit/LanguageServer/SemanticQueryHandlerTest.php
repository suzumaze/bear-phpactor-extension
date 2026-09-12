<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\LanguageServer;

use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;
use PHPUnit\Framework\TestCase;

use function Amp\Promise\wait;

final class SemanticQueryHandlerTest extends TestCase
{
    public function testReturnsWorkspaceRelativeResourceFact(): void
    {
        $response = wait((new SemanticQueryHandler(self::fixtureDir()))->resolveResource(
            'app://self/user',
            'src/Client.php',
        ));

        self::assertSame([
            'status' => 'ok',
            'data' => [
                'uri' => 'app://self/user',
                'fqn' => 'Acme\\Blog\\Resource\\App\\User',
                'path' => 'src/Resource/App/User.php',
            ],
            'candidates' => [],
        ], $response);
    }

    public function testReturnsStatusEnvelopeForInvalidAndMissingInput(): void
    {
        $handler = new SemanticQueryHandler(self::fixtureDir());

        self::assertSame(
            ['status' => 'invalid_input', 'data' => null, 'candidates' => []],
            wait($handler->resolveResource('not-a-resource-uri')),
        );
        self::assertSame(
            ['status' => 'not_found', 'data' => null, 'candidates' => []],
            wait($handler->resolveResource('app://self/missing')),
        );
        self::assertSame(
            ['status' => 'invalid_input', 'data' => null, 'candidates' => []],
            wait($handler->resolveResource('app://self/user', '../outside.php')),
        );
    }

    public function testResolvesRouteFact(): void
    {
        $response = wait((new SemanticQueryHandler($this->fixture('Router')))->resolveRoute(
            '/thing/detail',
            'aura.route.php',
        ));

        self::assertSame('ok', $response['status']);
        self::assertSame('/thing/detail', $response['data']['route']);
        self::assertSame('page://self/thing/detail', $response['data']['resource']['uri']);
        self::assertSame('lib/Resource/Page/Thing/Detail.php', $response['data']['resource']['path']);
    }

    public function testResolvesSqlFact(): void
    {
        $response = wait((new SemanticQueryHandler($this->fixture('Sql/App1')))->resolveSql(
            'point_distance',
            'src/Query/PointQueryInterface.php',
        ));

        self::assertSame('ok', $response['status']);
        self::assertSame([
            'queryId' => 'point_distance',
            'path' => 'var/db/sql/point_distance.sql',
        ], $response['data']);
    }

    public function testResolvesTemplateReferenceFact(): void
    {
        $response = wait((new SemanticQueryHandler($this->fixture('Template')))->resolveTemplate(
            'qiq',
            './sibling',
            'var/qiq/template/Page/Nested/RelativeReferences.php',
        ));

        self::assertSame('ok', $response['status']);
        self::assertSame('var/qiq/template/Page/Nested/sibling.php', $response['data']['path']);
    }

    public function testResolvesResourceTemplateFact(): void
    {
        $response = wait((new SemanticQueryHandler($this->fixture('Template/basic')))->resolveResourceTemplate(
            'app://self/user',
            'twig',
            'src/Resource/App/User.php',
        ));

        self::assertSame('ok', $response['status']);
        self::assertSame('app://self/user', $response['data']['resource']['uri']);
        self::assertSame('var/templates/App/User.html.twig', $response['data']['path']);
    }

    public function testResolvesAlpsDescriptorFact(): void
    {
        $response = wait((new SemanticQueryHandler($this->fixture('Alps/App1')))->resolveAlpsDescriptor(
            'goArticle',
            'src/Resource/App/AlpsDemo.php',
        ));

        self::assertSame('ok', $response['status']);
        self::assertSame('var/alps/profile.json', $response['data']['profilePath']);
        self::assertSame(333, $response['data']['byteOffset']);
    }

    public function testListsOnlyReadOnlySemanticMethods(): void
    {
        self::assertSame([
            'bear/resource/resolve' => 'resolveResource',
            'bear/route/resolve' => 'resolveRoute',
            'bear/sql/resolve' => 'resolveSql',
            'bear/template/resolve' => 'resolveTemplate',
            'bear/template/forResource' => 'resolveResourceTemplate',
            'bear/alps/resolveDescriptor' => 'resolveAlpsDescriptor',
        ], (new SemanticQueryHandler(self::fixtureDir()))->methods());
    }

    private static function fixtureDir(): string
    {
        $fixture = realpath(dirname(__DIR__, 2) . '/Fixture/Resource');
        self::assertNotFalse($fixture);

        return $fixture;
    }

    private function fixture(string $path): string
    {
        $fixture = realpath(dirname(__DIR__, 2) . '/Fixture/' . $path);
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
