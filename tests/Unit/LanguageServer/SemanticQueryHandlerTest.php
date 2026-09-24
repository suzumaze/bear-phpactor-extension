<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\LanguageServer;

use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;
use PHPUnit\Framework\TestCase;

use function Amp\Promise\wait;

final class SemanticQueryHandlerTest extends TestCase
{
    public function testDescribesProjectWithoutAbsolutePaths(): void
    {
        $handler = new SemanticQueryHandler(self::fixtureDir());
        $response = wait($handler->describeProject());

        self::assertSame('ok', $response['status']);
        self::assertSame(1, $response['data']['semanticApiVersion']);
        self::assertSame('bear-semantic', $response['data']['semanticProtocol']);
        self::assertSame(array_keys($handler->methods()), $response['data']['requests']);
        self::assertSame('Resource', $response['data']['workspaceName']);
        self::assertSame('.', $response['data']['projectPath']);
        self::assertSame('composer.json', $response['data']['composerPath']);
        self::assertSame([
            ['namespace' => 'Acme\\Blog\\', 'path' => 'src'],
            ['namespace' => 'Acme\\Tags\\', 'path' => 'imported-tags'],
        ], $response['data']['psr4Roots']);
        self::assertSame(0, $response['data']['excludedPsr4Roots']);
        self::assertGreaterThan(0, $response['data']['resourceCount']);
        self::assertContains('resourceDescription', $response['data']['capabilities']);
        self::assertContains('resourceAttributeFacts', $response['data']['capabilities']);
        self::assertContains('resourceAttributeInventory', $response['data']['capabilities']);
        self::assertContains('contractComparison', $response['data']['capabilities']);
        self::assertSame([
            ['source' => 'derived', 'freshness' => 'saved'],
            ['source' => 'file', 'path' => 'composer.json', 'freshness' => 'saved'],
        ], $response['provenance']);
        self::assertStringNotContainsString(
            self::fixtureDir(),
            json_encode($response, JSON_THROW_ON_ERROR),
        );
    }

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
            'provenance' => [[
                'source' => 'file',
                'path' => 'src/Resource/App/User.php',
                'freshness' => 'saved',
            ]],
        ], $response);
    }

    public function testReturnsStatusEnvelopeForInvalidAndMissingInput(): void
    {
        $handler = new SemanticQueryHandler(self::fixtureDir());

        self::assertSame(
            [
                'status' => 'invalid_input',
                'data' => null,
                'candidates' => [],
                'provenance' => [],
                'error' => [
                    'code' => 'semantic_invalid_input',
                    'message' => 'The semantic query input is invalid.',
                ],
            ],
            wait($handler->resolveResource('not-a-resource-uri')),
        );
        self::assertSame(
            [
                'status' => 'not_found',
                'data' => null,
                'candidates' => [],
                'provenance' => [],
                'error' => [
                    'code' => 'semantic_not_found',
                    'message' => 'No semantic target was found.',
                ],
            ],
            wait($handler->resolveResource('app://self/missing')),
        );
        self::assertSame(
            [
                'status' => 'invalid_input',
                'data' => null,
                'candidates' => [],
                'provenance' => [],
                'error' => [
                    'code' => 'semantic_invalid_input',
                    'message' => 'The semantic query input is invalid.',
                ],
            ],
            wait($handler->resolveResource('app://self/user', '../outside.php')),
        );
    }

    public function testAmbiguousResponseHasCandidatesAndStableError(): void
    {
        $handler = new SemanticQueryHandler($this->fixture('References'));
        $response = wait($handler->resolveResource(
            'page://self/x',
            'src/Resource/App/AmbiguousPage.php',
        ));

        self::assertSame('ambiguous', $response['status']);
        self::assertNull($response['data']);
        self::assertSame([
            'semantic_ambiguous',
            'More than one semantic target matched.',
        ], array_values($response['error']));
        self::assertSame([], $response['provenance']);
        self::assertSame([
            'src/Resource/Page/Admin/X.php',
            'src/Resource/Page/Content/X.php',
        ], array_column($response['candidates'], 'path'));
    }

    public function testListsWorkspaceRelativeResourceFacts(): void
    {
        $response = wait((new SemanticQueryHandler(self::fixtureDir()))->listResources('app', 'user', 1));

        self::assertSame([
            'status' => 'ok',
            'data' => [
                'resources' => [[
                    'uri' => 'app://self/user',
                    'fqn' => 'Acme\\Blog\\Resource\\App\\User',
                    'path' => 'src/Resource/App/User.php',
                ]],
                'total' => 1,
                'offset' => 0,
                'truncated' => false,
            ],
            'candidates' => [],
            'provenance' => [
                ['source' => 'derived', 'freshness' => 'saved'],
                ['source' => 'file', 'path' => 'composer.json', 'freshness' => 'saved'],
                [
                    'source' => 'file',
                    'path' => 'src/Resource/App/User.php',
                    'freshness' => 'saved',
                ],
            ],
        ], $response);
    }

    public function testDescribesResourceMethodsAndRelations(): void
    {
        $response = wait((new SemanticQueryHandler($this->fixture('Template/basic')))->describeResource(
            'app://self/dashboard',
            'src/Resource/App/Dashboard.php',
        ));

        self::assertSame('ok', $response['status']);
        self::assertSame('src/Resource/App/Dashboard.php', $response['data']['resource']['path']);
        self::assertSame('onGet', $response['data']['methods'][0]['name']);
        self::assertSame([], $response['data']['methods'][0]['parameters']);
        self::assertSame([
            'status' => 'unsupported',
            'names' => [],
            'reason' => 'no_complete_body_assignment',
        ], $response['data']['methods'][0]['responseBody']);
        self::assertSame('embed', $response['data']['relationsOut'][0]['kind']);
        self::assertSame('app://self/missing', $response['data']['relationsOut'][0]['targetUri']);
        self::assertSame('src/Resource/App/Dashboard.php', $response['data']['relationsOut'][0]['sourcePath']);
        self::assertTrue($response['data']['relationsIn']['available']);
        self::assertSame([], $response['data']['relationsIn']['items']);
        self::assertSame(0, $response['data']['relationsIn']['total']);
        self::assertFalse($response['data']['relationsIn']['truncated']);
        self::assertSame([
            ['engine' => 'qiq', 'path' => 'var/qiq/template/App/Dashboard.php'],
            ['engine' => 'twig', 'path' => 'var/templates/App/Dashboard.html.twig'],
        ], $response['data']['templates']);
        self::assertSame([], $response['data']['schemas']);
    }

    public function testDescribesStaticallyProvenResponseBodyNames(): void
    {
        $response = wait((new SemanticQueryHandler($this->fixture('Contract')))->describeResource(
            'app://self/user',
            'src/Resource/App/User.php',
        ));

        self::assertSame('ok', $response['status']);
        $methods = array_column($response['data']['methods'], null, 'name');
        self::assertSame([
            'status' => 'ok',
            'names' => ['id', 'name'],
            'reason' => null,
        ], $methods['onGet']['responseBody']);
        self::assertSame('unsupported', $methods['onPost']['responseBody']['status']);
    }

    public function testDescribesResourceAttributesWithStaticEvidence(): void
    {
        $response = wait((new SemanticQueryHandler($this->fixture('Template/basic')))->describeResourceAttributes(
            'app://self/dashboard',
            'src/Resource/App/Dashboard.php',
        ));

        self::assertSame('ok', $response['status']);
        self::assertSame('app://self/dashboard', $response['data']['resource']['uri']);
        self::assertCount(7, $response['data']['attributes']);
        self::assertSame('method', $response['data']['attributes'][0]['target']);
        self::assertSame('onGet', $response['data']['attributes'][0]['method']);
        self::assertSame('Embed', $response['data']['attributes'][0]['name']);
        self::assertSame('BEAR\\Resource\\Annotation\\Embed', $response['data']['attributes'][0]['fqn']);
        self::assertSame([
            ['name' => 'rel', 'type' => 'string', 'value' => 'user'],
            ['name' => 'src', 'type' => 'string', 'value' => 'app://self/user'],
        ], $response['data']['attributes'][0]['arguments']);
        self::assertGreaterThan(
            $response['data']['attributes'][0]['byteRange']['start'],
            $response['data']['attributes'][0]['byteRange']['end'],
        );
        self::assertSame([
            'source' => 'explicit_only',
            'constructorDefaultsExpanded' => false,
        ], $response['data']['argumentPolicy']);
    }

    public function testIndexesResourceAttributesWithPerResourceStatus(): void
    {
        $response = wait((new SemanticQueryHandler($this->fixture('Template/basic')))->indexResourceAttributes(
            'app',
            '',
            10,
        ));

        self::assertSame('ok', $response['status']);
        self::assertSame(3, $response['data']['total']);
        self::assertSame(0, $response['data']['offset']);
        self::assertFalse($response['data']['truncated']);
        self::assertSame(
            ['app://self/dashboard', 'app://self/other', 'app://self/user'],
            array_column(array_column($response['data']['items'], 'resource'), 'uri'),
        );
        self::assertSame(['ok', 'ok', 'ok'], array_column($response['data']['items'], 'status'));
        self::assertCount(7, $response['data']['items'][0]['attributes']);
        self::assertSame([], $response['data']['items'][1]['attributes']);
        self::assertSame([], $response['data']['items'][2]['attributes']);
        self::assertSame([
            'source' => 'explicit_only',
            'constructorDefaultsExpanded' => false,
        ], $response['data']['argumentPolicy']);
    }

    public function testComparesContractNamePresenceWithoutClaimingTypeEquality(): void
    {
        $response = wait((new SemanticQueryHandler($this->fixture('Contract')))->compareContract(
            'app://self/user',
            'onPost',
            'request',
            contextPath: 'src/Resource/App/User.php',
        ));

        self::assertSame('ok', $response['status']);
        self::assertSame('app://self/user', $response['data']['resource']['uri']);
        self::assertSame('onPost', $response['data']['method']);
        self::assertSame('request', $response['data']['schemaKind']);
        self::assertSame(['ok', 'ok', 'ok'], array_column($response['data']['surfaces'], 'status'));
        self::assertSame(['resource', 'schema', 'alps'], $response['data']['comparison']['compared']);
        self::assertSame(['id'], $response['data']['comparison']['common']);
        self::assertSame([
            ['name' => '200', 'sources' => ['schema']],
            ['name' => 'email', 'sources' => ['schema', 'alps']],
            ['name' => 'id', 'sources' => ['resource', 'schema', 'alps']],
            ['name' => 'name', 'sources' => ['resource', 'schema']],
        ], $response['data']['comparison']['presence']);
    }

    public function testReportsProjectContractCoverageWithoutTreatingAbsenceAsFailure(): void
    {
        $response = wait((new SemanticQueryHandler($this->fixture('Contract')))->inspectContractCoverage());

        self::assertSame('ok', $response['status']);
        self::assertSame(4, $response['data']['total']);
        self::assertSame(4, $response['data']['matchingTotal']);
        self::assertSame(0, $response['data']['offset']);
        self::assertFalse($response['data']['truncated']);
        self::assertFalse($response['data']['gapsOnly']);
        self::assertNull($response['data']['scheme']);
        self::assertSame(1, $response['data']['scannedResources']);
        self::assertSame(1, $response['data']['analyzedResources']);
        self::assertFalse($response['data']['resourceScanTruncated']);
        self::assertSame(2, $response['data']['summary']['coveredMethods']);
        self::assertSame(4, $response['data']['summary']['schemes']['app']['methods']);

        $items = [];
        foreach ($response['data']['items'] as $item) {
            $items[$item['method']] = $item;
        }
        self::assertTrue($items['onGet']['covered']);
        self::assertSame('not_applicable', $items['onGet']['surfaces']['requestSchema']['state']);
        self::assertSame('available', $items['onGet']['surfaces']['responseSchema']['state']);
        self::assertFalse($items['onPatch']['covered']);
        self::assertSame(
            ['requestSchema', 'alps'],
            $items['onPatch']['gaps'],
        );
        self::assertSame('dynamic', $items['onPatch']['surfaces']['alps']['state']);

        $gaps = wait((new SemanticQueryHandler($this->fixture('Contract')))->inspectContractCoverage(
            limit: 1,
            offset: 1,
            gapsOnly: true,
        ));
        self::assertSame(4, $gaps['data']['total']);
        self::assertSame(2, $gaps['data']['matchingTotal']);
        self::assertSame(1, $gaps['data']['offset']);
        self::assertTrue($gaps['data']['gapsOnly']);
        self::assertFalse($gaps['data']['truncated']);
        self::assertCount(1, $gaps['data']['items']);

        $pages = wait((new SemanticQueryHandler($this->fixture('Contract')))->inspectContractCoverage(
            scheme: 'page',
        ));
        self::assertSame('ok', $pages['status']);
        self::assertSame('page', $pages['data']['scheme']);
        self::assertSame(4, $pages['data']['total']);
        self::assertSame(0, $pages['data']['matchingTotal']);
        self::assertSame(4, $pages['data']['summary']['methods']);
    }

    public function testFindsIncomingResourceRelations(): void
    {
        $handler = new SemanticQueryHandler($this->fixture('Template/basic'));
        $response = wait($handler->findIncomingResourceRelations(
            'app://self/user',
            'src/Resource/App/User.php',
            2,
        ));

        self::assertSame('ok', $response['status']);
        self::assertSame('app://self/user', $response['data']['resource']['uri']);
        self::assertTrue($response['data']['available']);
        self::assertSame(3, $response['data']['total']);
        self::assertTrue($response['data']['truncated']);
        self::assertCount(2, $response['data']['items']);
        self::assertSame('app://self/dashboard', $response['data']['items'][0]['sourceUri']);
        self::assertSame('src/Resource/App/Dashboard.php', $response['data']['items'][0]['sourcePath']);
    }

    public function testFindsBoundedResourceReferencesWithoutADocumentPosition(): void
    {
        $handler = new SemanticQueryHandler($this->fixture('References'));
        $response = wait($handler->findResourceReferences(
            'app://self/article',
            'src/Resource/App/Article.php',
            2,
        ));

        self::assertSame('ok', $response['status']);
        self::assertSame('app://self/article', $response['data']['resource']['uri']);
        self::assertSame(3, $response['data']['total']);
        self::assertTrue($response['data']['truncated']);
        self::assertSame([
            'src/Resource/App/Articles.php',
            'src/Resource/Page/Admin/Article.php',
        ], array_column($response['data']['references'], 'path'));
        self::assertSame(
            ['resource_uri', 'resource_uri'],
            array_column($response['data']['references'], 'kind'),
        );
        self::assertStringNotContainsString(
            $this->fixture('References'),
            json_encode($response, JSON_THROW_ON_ERROR),
        );

        $invalid = wait($handler->findResourceReferences('app://self/article', limit: 0));
        self::assertSame('invalid_input', $invalid['status']);
        self::assertSame('semantic_invalid_input', $invalid['error']['code']);
    }

    public function testResourceDescriptionIncludesConventionSchema(): void
    {
        $response = wait((new SemanticQueryHandler($this->fixture('Body/basic')))->describeResource(
            'app://self/user',
            'src/Resource/App/User.php',
        ));

        self::assertSame('ok', $response['status']);
        self::assertSame([], $response['data']['templates']);
        self::assertSame('response', $response['data']['schemas'][0]['kind']);
        self::assertSame('convention', $response['data']['schemas'][0]['source']);
        self::assertSame('var/json_schema/user.json', $response['data']['schemas'][0]['path']);
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
        self::assertSame([[
            'source' => 'file',
            'path' => 'var/db/sql/point_distance.sql',
            'freshness' => 'saved',
        ]], $response['provenance']);
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
        self::assertSame([
            'src/Resource/App/User.html.twig',
            'var/templates/App/User.html.twig',
        ], $response['data']['searched']);
    }

    public function testMissingResourceTemplateExplainsResolvedResourceAndSearchPaths(): void
    {
        $response = wait((new SemanticQueryHandler($this->fixture('Resource')))->resolveResourceTemplate(
            'app://self/user',
            'twig',
        ));

        self::assertSame('not_found', $response['status']);
        self::assertNull($response['data']);
        self::assertSame('app://self/user', $response['partial']['resource']['uri']);
        self::assertSame('src/Resource/App/User.php', $response['partial']['resource']['path']);
        self::assertNull($response['partial']['path']);
        self::assertSame([
            'src/Resource/App/User.html.twig',
            'var/templates/App/User.html.twig',
        ], $response['partial']['searched']);
        self::assertSame([
            ['source' => 'derived', 'freshness' => 'saved'],
            [
                'source' => 'file',
                'path' => 'src/Resource/App/User.php',
                'freshness' => 'saved',
            ],
        ], $response['provenance']);
    }

    public function testMissingResourceTemplateHasNoPartialResult(): void
    {
        $response = wait((new SemanticQueryHandler($this->fixture('Resource')))->resolveResourceTemplate(
            'app://self/missing',
            'twig',
        ));

        self::assertSame([
            'status' => 'not_found',
            'data' => null,
            'candidates' => [],
            'provenance' => [],
            'error' => [
                'code' => 'semantic_not_found',
                'message' => 'No semantic target was found.',
            ],
        ], $response);
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

    public function testDescribesAlpsDescriptorRelationships(): void
    {
        $response = wait((new SemanticQueryHandler($this->fixture('Alps/App1')))->describeAlpsDescriptor(
            'goArticle',
            'src/Resource/App/AlpsDemo.php',
        ));

        self::assertSame('ok', $response['status']);
        self::assertSame('var/alps/profile.json', $response['data']['profilePath']);
        self::assertSame('safe', $response['data']['type']);
        self::assertSame('#Article', $response['data']['rt']);
        self::assertSame('Article', $response['data']['relationsOut'][0]['targetId']);
        self::assertSame('rt', $response['data']['relationsOut'][0]['kind']);
        self::assertSame('ok', $response['data']['relationsOut'][0]['targetStatus']);
        self::assertSame('Article', $response['data']['relationsIn'][0]['sourceId']);
        self::assertSame('href', $response['data']['relationsIn'][0]['kind']);
    }

    public function testResolvesNamedAndResourceSchemaFacts(): void
    {
        $handler = new SemanticQueryHandler($this->fixture('JsonSchema/basic'));

        $named = wait($handler->resolveNamedSchema(
            'user-params.json',
            'request',
            'src/Resource/App/SchemaDemo.php',
        ));
        self::assertSame('ok', $named['status']);
        self::assertSame('attribute', $named['data']['source']);
        self::assertSame('var/json_validate/user-params.json', $named['data']['path']);

        $resource = wait($handler->resolveResourceSchema(
            'app://self/bodyTypeDemo',
            contextPath: 'src/Resource/App/BodyTypeDemo.php',
        ));
        self::assertSame('ok', $resource['status']);
        self::assertSame('convention', $resource['data']['source']);
        self::assertSame('var/json_schema/body-type-demo.json', $resource['data']['path']);
        self::assertSame('app://self/bodyTypeDemo', $resource['data']['resource']['uri']);

        $namedFacts = wait($handler->describeNamedSchema(
            'user-params.json',
            'request',
            'src/Resource/App/SchemaDemo.php',
        ));
        self::assertSame('ok', $namedFacts['status']);
        self::assertTrue($namedFacts['data']['available']);
        self::assertSame(['object'], $namedFacts['data']['types']);
        self::assertSame([
            ['name' => 'id', 'required' => false, 'types' => ['integer']],
        ], $namedFacts['data']['properties']);

        $resourceFacts = wait($handler->describeResourceSchema(
            'app://self/bodyTypeDemo',
            contextPath: 'src/Resource/App/BodyTypeDemo.php',
        ));
        self::assertSame('ok', $resourceFacts['status']);
        self::assertSame('var/json_schema/body-type-demo.json', $resourceFacts['data']['path']);
        self::assertSame([
            ['name' => 'name', 'required' => false, 'types' => ['string']],
        ], $resourceFacts['data']['properties']);
    }

    public function testReturnsBoundedDiAndAopSourceInventories(): void
    {
        $handler = new SemanticQueryHandler($this->fixture('DiAop'));

        $bindings = wait($handler->inspectDiBindings(limit: 1));
        self::assertSame('ok', $bindings['status']);
        self::assertSame(3, $bindings['data']['total']);
        self::assertSame(2, $bindings['data']['unresolved']);
        self::assertTrue($bindings['data']['truncated']);
        self::assertSame('resolved', $bindings['data']['items'][0]['state']);
        self::assertSame('src/Module/AppModule.php', $bindings['data']['items'][0]['path']);

        $pointcuts = wait($handler->inspectAopPointcuts(
            'Acme\\DiAop\\Interceptor\\TraceInterceptor',
        ));
        self::assertSame('ok', $pointcuts['status']);
        self::assertSame(1, $pointcuts['data']['total']);
        self::assertTrue($pointcuts['data']['items'][0]['priority']);
        self::assertSame('logical_not', $pointcuts['data']['items'][0]['methodMatcher']['kind']);
        self::assertStringNotContainsString(
            $this->fixture('DiAop'),
            json_encode([$bindings, $pointcuts], JSON_THROW_ON_ERROR),
        );
    }

    public function testListsOnlyReadOnlySemanticMethods(): void
    {
        self::assertSame([
            'bear/project/info' => 'describeProject',
            'bear/project/diagnostics' => 'diagnoseProject',
            'bear/project/contractCoverage' => 'inspectContractCoverage',
            'bear/resource/resolve' => 'resolveResource',
            'bear/resource/list' => 'listResources',
            'bear/resource/describe' => 'describeResource',
            'bear/resource/attributes' => 'describeResourceAttributes',
            'bear/resource/attributeIndex' => 'indexResourceAttributes',
            'bear/resource/incomingRelations' => 'findIncomingResourceRelations',
            'bear/resource/references' => 'findResourceReferences',
            'bear/contract/compare' => 'compareContract',
            'bear/route/resolve' => 'resolveRoute',
            'bear/sql/resolve' => 'resolveSql',
            'bear/template/resolve' => 'resolveTemplate',
            'bear/template/forResource' => 'resolveResourceTemplate',
            'bear/alps/resolveDescriptor' => 'resolveAlpsDescriptor',
            'bear/alps/describeDescriptor' => 'describeAlpsDescriptor',
            'bear/schema/resolveNamed' => 'resolveNamedSchema',
            'bear/schema/forResource' => 'resolveResourceSchema',
            'bear/schema/describeNamed' => 'describeNamedSchema',
            'bear/schema/describeForResource' => 'describeResourceSchema',
            'bear/di/bindings' => 'inspectDiBindings',
            'bear/aop/pointcuts' => 'inspectAopPointcuts',
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
