<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Di;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;

use function Amp\Promise\wait;

final class DiModuleDeclarationsQueryTest extends TestCase
{
    public function testReturnsExactModuleDeclarationsWithoutContextOrValueExpressions(): void
    {
        $response = wait((new SemanticQueryHandler($this->fixture()))->inspectDiModuleDeclarations(
            'Acme\\DiAop\\Module\\FeatureModule',
        ));

        self::assertSame('ok', $response['status']);
        self::assertNull($response['data']['applicationContext']);
        self::assertSame('Acme\\DiAop\\Module\\FeatureModule', $response['data']['module']);
        self::assertSame('not_requested', $response['data']['contextMembership']['state']);
        self::assertSame('not_joined', $response['data']['bindingSelection']['state']);
        self::assertGreaterThan(0, $response['data']['bindings']['total']);
        self::assertSame(0, $response['data']['pointcuts']['total']);
        self::assertSame('src/Module/FeatureModule.php', $response['data']['bindings']['items'][0]['path']);
        self::assertSame(24, $response['data']['bindings']['items'][0]['line']);
        self::assertSame('direct_workspace_source', $response['data']['coverage']['declarations']);
        $serialized = json_encode($response['data'], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('targetExpression', $serialized);
        self::assertStringNotContainsString('constructorArguments', $serialized);
        self::assertStringNotContainsString('host=smtp_host', $serialized);
        self::assertStringNotContainsString('queue_host', $serialized);
    }

    public function testAddsWorkspaceContextMembershipRouteOnlyWhenRequested(): void
    {
        $response = wait((new SemanticQueryHandler($this->fixture()))->inspectDiModuleDeclarations(
            'Acme\\DiAop\\Module\\FeatureModule',
            'test',
        ));

        self::assertSame('ok', $response['status']);
        self::assertSame('present_in_workspace_graph', $response['data']['contextMembership']['state']);
        self::assertSame(
            ['extends', 'install'],
            array_column($response['data']['contextMembership']['route'], 'kind'),
        );
        self::assertSame(
            'Acme\\DiAop\\Module\\TestModule',
            $response['data']['contextMembership']['route'][0]['source'],
        );
        self::assertFalse($response['data']['coverage']['vendorModulesExpanded']);
        self::assertFalse($response['data']['coverage']['dynamicEdgesExpanded']);
        self::assertFalse($response['data']['coverage']['bindingWinnerResolved']);
    }

    public function testPointcutFactsIncludeModuleLineAndPaginationIsBounded(): void
    {
        $response = wait((new SemanticQueryHandler($this->fixture()))->inspectDiModuleDeclarations(
            'Acme\\DiAop\\Module\\AppModule',
            'app',
            limit: 1,
        ));

        self::assertSame('ok', $response['status']);
        self::assertSame('present_in_workspace_graph', $response['data']['contextMembership']['state']);
        self::assertSame(3, $response['data']['pointcuts']['total']);
        self::assertCount(1, $response['data']['pointcuts']['items']);
        self::assertTrue($response['data']['pointcuts']['truncated']);
        self::assertSame(24, $response['data']['pointcuts']['items'][0]['line']);
        self::assertSame('Acme\\DiAop\\Module\\AppModule', $response['data']['pointcuts']['items'][0]['module']);
    }

    public function testRequiresAnExactModuleFqcn(): void
    {
        $handler = new SemanticQueryHandler($this->fixture());
        self::assertSame('invalid_input', wait($handler->inspectDiModuleDeclarations(''))['status']);
        self::assertSame('invalid_input', wait($handler->inspectDiModuleDeclarations('Module'))['status']);
        self::assertSame(
            'invalid_input',
            wait($handler->inspectDiModuleDeclarations('Acme\\DiAop\\Module', limit: 101))['status'],
        );
    }

    private function fixture(): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/DiAop');
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
