<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Attribute;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;
use Suzumaze\BearPhpactor\Semantic\Attribute\AttributeCatalogQuery;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSourceIndex;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

use function Amp\Promise\wait;

final class AttributeCatalogQueryTest extends TestCase
{
    public function testDefinitionsDocumentationAndContextReferences(): void
    {
        $result = wait($this->handler()->listAttributeCatalog('advice-app', 'Acme\\Shop\\Annotation\\First'));
        self::assertSame('ok', $result['status']);
        self::assertSame(1, $result['data']['total']);
        self::assertSame('targeted_composer_definition', $result['data']['coverage']['scanMode']);
        self::assertSame(1, $result['data']['scannedFiles']);
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
        self::assertSame('targeted_composer_definition', $result['data']['coverage']['scanMode']);
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

    public function testUnresolvedExactNameFallsBackToBoundedCaseInsensitiveScan(): void
    {
        $caseMismatch = wait($this->handler()->listAttributeCatalog(attribute: 'acme\\shop\\annotation\\first'));
        self::assertSame(1, $caseMismatch['data']['total']);
        self::assertSame('bounded_composer_scan', $caseMismatch['data']['coverage']['scanMode']);

        $missing = wait($this->handler()->listAttributeCatalog(attribute: 'Acme\\Shop\\Annotation\\Missing'));
        self::assertSame(0, $missing['data']['total']);
        self::assertSame('bounded_composer_scan', $missing['data']['coverage']['scanMode']);
    }

    public function testClassmapLookupCountsEveryReadCandidate(): void
    {
        $root = $this->temporaryRoot();
        try {
            $this->prepareRoot($root, ['classmap' => ['classmap/first.php', 'classmap/second.php']]);
            mkdir($root . '/classmap', 0700, true);
            $first = "<?php\nnamespace Mapped;\nclass Other {}\n";
            $second = "<?php\nnamespace Mapped;\n#[\\Attribute]\nclass Target {}\n";
            file_put_contents($root . '/classmap/first.php', $first);
            file_put_contents($root . '/classmap/second.php', $second);

            $lookup = (new ClassSourceIndex($root))->findComposerMapped('Mapped\\Target');
            self::assertNotNull($lookup['source']);
            self::assertSame(2, $lookup['scannedFiles']);
            self::assertSame(strlen($first) + strlen($second), $lookup['readBytes']);
            self::assertFalse($lookup['truncated']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testOversizedMappedAttributeFallsBackWithoutClaimingAbsence(): void
    {
        $root = $this->temporaryRoot();
        try {
            $this->prepareRoot($root, ['psr-4' => ['Large\\' => 'src/']]);
            mkdir($root . '/src', 0700, true);
            $source = "<?php\nnamespace Large;\n#[\\Attribute]\nclass HugeAttribute {}\n"
                . str_repeat("// padded source\n", 70000);
            file_put_contents($root . '/src/HugeAttribute.php', $source);
            $workspace = WorkspaceContext::fromRoot($root)->value;
            self::assertNotNull($workspace);

            $result = (new AttributeCatalogQuery())->listInWorkspace(
                $workspace,
                attribute: 'Large\\HugeAttribute',
            )->value;

            self::assertNotNull($result);
            self::assertSame(0, $result['total']);
            self::assertSame('bounded_composer_scan', $result['coverage']['scanMode']);
            self::assertTrue($result['scanTruncated']);
            self::assertGreaterThan(0, $result['skippedFiles']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function handler(): SemanticQueryHandler
    {
        return new SemanticQueryHandler(dirname(__DIR__, 3) . '/Fixture/DiComposition');
    }

    /** @param array<string, mixed> $autoload */
    private function prepareRoot(string $root, array $autoload): void
    {
        mkdir($root . '/vendor/composer', 0700, true);
        file_put_contents(
            $root . '/composer.json',
            json_encode(['autoload' => $autoload, 'name' => 'test/catalog'], JSON_THROW_ON_ERROR),
        );
        file_put_contents($root . '/vendor/composer/installed.json', '{"packages":[],"dev":true}');
    }

    private function temporaryRoot(): string
    {
        $root = sys_get_temp_dir() . '/bear-attribute-catalog-' . bin2hex(random_bytes(8));
        mkdir($root, 0700, true);

        return $root;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
