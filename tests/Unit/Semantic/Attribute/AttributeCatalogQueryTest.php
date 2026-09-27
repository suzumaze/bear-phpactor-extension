<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Attribute;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSourceIndex;

use function Amp\Promise\wait;

final class AttributeCatalogQueryTest extends TestCase
{
    public function testDefinitionsDocumentationAndContextReferences(): void
    {
        $result = wait($this->handler()->listAttributeCatalog('advice-app', 'Acme\\Shop\\Annotation\\First'));
        self::assertSame('ok', $result['status']);
        self::assertSame(1, $result['data']['total']);
        $item = $result['data']['items'][0];
        self::assertSame(['method'], $item['targets']);
        self::assertTrue($item['repeatable']);
        self::assertSame('application', $item['origin']['kind']);
        self::assertStringContainsString('Fixture attribute documentation.', $item['docblock']);
        self::assertSame(
            ['name' => 'label', 'type' => 'string', 'hasDefault' => true, 'variadic' => false],
            $item['constructorParameters'][0],
        );
        self::assertSame(2, $item['mechanismTotal']);
        self::assertSame('src/Interceptor/Old.php', $item['mechanisms'][0]['interceptors'][0]['invoke']['path']);
        self::assertFalse($result['data']['coverage']['aopReferencesProveApplication']);
        self::assertStringNotContainsString('fixture-private-default', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testNoContextIsChosenAndUnusedAttributesAreIncluded(): void
    {
        $result = wait($this->handler()->listAttributeCatalog(attribute: 'Acme\\Shop\\Annotation\\Unused'));
        self::assertNull($result['data']['applicationContext']);
        self::assertSame(1, $result['data']['total']);
        self::assertSame('unknown', $result['data']['items'][0]['consumerStatus']);
        self::assertFalse($result['data']['coverage']['contextEvaluated']);
        self::assertSame('invalid_input', wait($this->handler()->listAttributeCatalog(''))['status']);
    }

    public function testPackageAttributeAndDefaultTargets(): void
    {
        $result = wait($this->handler()->listAttributeCatalog(attribute: 'Ray\\Aop\\CatalogAttribute'));
        self::assertSame(1, $result['data']['total']);
        $item = $result['data']['items'][0];
        self::assertSame(['kind' => 'package', 'package' => 'ray/aop'], $item['origin']);
        self::assertCount(6, $item['targets']);
        self::assertFalse($item['repeatable']);
    }

    public function testUnsupportedAndIncompleteFlagArgumentsRemainUnknown(): void
    {
        $result = wait($this->handler()->listAttributeCatalog(attribute: 'Acme\\Shop\\Annotation\\InvalidFlags'));
        self::assertSame(1, $result['data']['total']);
        self::assertNull($result['data']['items'][0]['targets']);
        self::assertNull($result['data']['items'][0]['repeatable']);

        $incomplete = wait($this->handler()->listAttributeCatalog(
            attribute: 'Acme\\Shop\\Annotation\\IncompleteFlags',
        ));
        self::assertSame(1, $incomplete['data']['total']);
        self::assertNull($incomplete['data']['items'][0]['targets']);
        self::assertNull($incomplete['data']['items'][0]['repeatable']);
    }

    public function testDiscoveryIsBounded(): void
    {
        $scan = (new ClassSourceIndex(dirname(__DIR__, 3) . '/Fixture/DiComposition'))->attributeSources(1);
        self::assertSame(1, $scan['scannedFiles']);
        self::assertTrue($scan['truncated']);
    }

    private function handler(): SemanticQueryHandler
    {
        return new SemanticQueryHandler(dirname(__DIR__, 3) . '/Fixture/DiComposition');
    }
}
