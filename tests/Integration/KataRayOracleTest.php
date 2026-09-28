<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Integration;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Aop\AopApplicationsQuery;
use Suzumaze\BearPhpactor\Semantic\App\AppContextListQuery;
use Suzumaze\BearPhpactor\Semantic\Attribute\AttributeCatalogQuery;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSourceIndex;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ModuleInterpreter;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

final class KataRayOracleTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStaticResultsAgreeWithLockedRayImplementations(): void
    {
        $kataRoot = getenv('BEAR_KATA_VERIFY_ROOT');
        if (!is_string($kataRoot) || $kataRoot === '' || !is_file($kataRoot . '/vendor/autoload.php')) {
            self::markTestSkipped(
                'Set BEAR_KATA_VERIFY_ROOT to an isolated Kata checkout with locked vendor dependencies.',
            );
        }
        require_once $kataRoot . '/vendor/autoload.php';
        $fixtureRoot = dirname(__DIR__) . '/Fixture/DiComposition';
        spl_autoload_register(static function (string $class) use ($fixtureRoot): void {
            $prefix = 'Acme\\Shop\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $file = $fixtureRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });

        $fixtureWorkspace = WorkspaceContext::fromRoot($fixtureRoot)->value;
        self::assertNotNull($fixtureWorkspace);
        $fixtureAop = (new AopApplicationsQuery())->listInWorkspace(
            $fixtureWorkspace,
            'advice-app',
            uri: 'app://self/advice',
        )->value;
        self::assertNotNull($fixtureAop);
        $staticItem = array_values(array_filter(
            $fixtureAop['items'],
            static fn (array $item): bool => $item['method'] === 'onGet',
        ))[0];
        $adviceModule = new \Acme\Shop\Module\AdviceModule();
        $rayBind = new \Ray\Aop\Bind();
        $rayBind->bind(\Acme\Shop\Resource\App\Advice::class, $adviceModule->getContainer()->getPointcuts());
        self::assertSame(
            array_column($staticItem['chain'], 'interceptor'),
            $rayBind->getBindings()['onGet'],
            'Source-matched priority, annotation order, duplicate-key replacement '
                . 'and duplicate interceptors must match Ray.Aop.',
        );

        $engine = new ModuleInterpreter(new ClassSourceIndex($fixtureRoot, $fixtureRoot));
        $probeDir = sys_get_temp_dir() . '/bear-kata-ray-oracle-' . bin2hex(random_bytes(8));
        if (!is_dir($probeDir)) {
            mkdir($probeDir, 0700, true);
        }
        try {
            foreach (
                [
                \Acme\Shop\Module\OracleInstallModule::class => \Acme\Shop\Service\FirstThing::class,
                \Acme\Shop\Module\OracleOverrideModule::class => \Acme\Shop\Service\SecondThing::class,
                ] as $moduleClass => $expectedTarget
            ) {
                $moduleValue = $engine->newObject($moduleClass);
                self::assertInstanceOf(\Suzumaze\BearPhpactor\Semantic\Di\Composition\ObjectValue::class, $moduleValue);
                $static = $engine->containerOf($moduleValue)->bindings[\Acme\Shop\Service\ThingInterface::class . '-'];
                $runtime = (new \Ray\Di\Injector(new $moduleClass(), $probeDir))
                    ->getInstance(\Acme\Shop\Service\ThingInterface::class);
                self::assertSame($expectedTarget, $static['target']);
                self::assertSame($static['target'], $runtime::class);
            }
        } finally {
            $this->removeDirectory($probeDir);
        }

        $kataWorkspace = WorkspaceContext::fromRoot($kataRoot)->value;
        self::assertNotNull($kataWorkspace);
        $contexts = (new AppContextListQuery())->listInWorkspace($kataWorkspace)->value;
        self::assertNotNull($contexts);
        $contextNames = array_column($contexts['items'], 'applicationContext');
        self::assertContains('test-hal-api-app', $contextNames);
        self::assertContains('html-test-hal-api-app', $contextNames);
        self::assertFalse($contexts['coverage']['overrideModulesApplied']);

        $catalog = (new AttributeCatalogQuery())->listInWorkspace(
            $kataWorkspace,
            'test-hal-api-app',
            attribute: 'Ray\\Csrf\\Attribute\\CsrfToken',
        )->value;
        self::assertNotNull($catalog);
        self::assertSame(1, $catalog['total']);
        self::assertSame(1, $catalog['scannedFiles']);
        self::assertFalse($catalog['scanTruncated']);
        self::assertSame('targeted_composer_definition', $catalog['coverage']['scanMode']);

        $csrf = (new AopApplicationsQuery())->listInWorkspace(
            $kataWorkspace,
            'test-hal-api-app',
            attribute: 'Ray\\Csrf\\Attribute\\CsrfToken',
        )->value;
        self::assertNotNull($csrf);
        self::assertCount(4, $csrf['items']);
        self::assertSame(124, $csrf['unknownTotal']);
        self::assertSame(124, $csrf['unresolvedPointcutTotal']);
        self::assertSame(100, count($csrf['unknowns']));
        self::assertSame(1, $csrf['unknownSummary']['compositionOccurrences']);
        self::assertSame(1, $csrf['unknownSummary']['resourceOccurrences']);
        self::assertSame(122, $csrf['unknownSummary']['applicationOccurrences']);
        self::assertSame(2, $csrf['unknownSummary']['unresolvedPointcutRegistrations']);
        self::assertSame(61, $csrf['unknownSummary']['resourceMethodsEvaluated']);
        self::assertSame(4, $csrf['unknownSummary']['filterMatchedMethods']);
        self::assertSame(8, $csrf['unknownSummary']['filterMatchedApplicationOccurrences']);
        self::assertSame(3, $csrf['unknownSummary']['groups']['total']);
        self::assertFalse($csrf['unknownSummary']['groups']['truncated']);
        $assistedMatcherGroup = array_values(array_filter(
            $csrf['unknownSummary']['groups']['items'],
            static fn (array $group): bool => $group['path'] === 'vendor/ray/di/src/di/AssistedInjectModule.php',
        ))[0];
        self::assertSame(122, $assistedMatcherGroup['occurrences']);
        self::assertSame(61, $assistedMatcherGroup['affectedMethodCount']);
        self::assertSame(2, $assistedMatcherGroup['composedRegistrations']);
        self::assertSame(
            $csrf['unknownTotal'],
            $csrf['unknownSummary']['compositionOccurrences']
                + $csrf['unknownSummary']['resourceOccurrences']
                + $csrf['unknownSummary']['applicationOccurrences'],
        );
        self::assertSame(
            $csrf['unresolvedPointcutTotal'],
            $csrf['unknownSummary']['unresolvedPointcutRegistrations']
                + $csrf['unknownSummary']['applicationOccurrences'],
        );
        self::assertSame('provisional', $csrf['items'][0]['status']);
        foreach ($csrf['items'] as $item) {
            self::assertSame(2, $item['unresolvedPointcutTotal']);
        }
        $csrfModule = new \Ray\Csrf\CsrfModule();
        foreach ($csrf['items'] as $item) {
            $rayBind = new \Ray\Aop\Bind();
            $rayBind->bind($item['resource'], $csrfModule->getContainer()->getPointcuts());
            self::assertSame(
                $rayBind->getBindings()[$item['method']],
                array_column($item['chain'], 'interceptor'),
                'Kata source-matched CSRF/SameOrigin chains must match real Ray.Aop Bind for the same resource method.',
            );
        }
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
