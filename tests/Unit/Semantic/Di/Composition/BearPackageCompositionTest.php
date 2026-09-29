<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Di\Composition;

use Suzumaze\BearPhpactor\Semantic\Di\Composition\BearPackageComposition;

/**
 * BEAR\Package\Module, PackageInjector::module(), and Ray\Di\Injector over the stub packages.
 */
final class BearPackageCompositionTest extends CompositionTestCase
{
    private const APP = 'Acme\\Shop\\Module\\';

    public function testComposesContextModulesInReverseOrderAndAppliesTheFixedRecipe(): void
    {
        [$s, $a] = [self::SERVICE, self::APP];
        [$pkg, $prod, $meta, $error] = [
            'BEAR\\Package\\',
            'BEAR\\Package\\Context\\ProdModule',
            'BEAR\\Package\\Module\\AppMetaModule',
            'BEAR\\Package\\Provide\\Error\\',
        ];
        [$ro, $roModule, $di] = ['Acme\\Shop\\Resource\\', 'BEAR\\Resource\\Module\\ResourceObjectModule', 'Ray\\Di\\'];
        $container = (new BearPackageComposition($this->classes, $this->interpreter))('Acme\\Shop', 'cli-prod-app');

        self::assertSame([
            "-{$pkg}Annotation\\AppName" => "string @{$meta}",
            '-BEAR\\RepositoryModule\\Annotation\\CacheDir'
                => "provider {$pkg}Provide\\Cache\\CacheDirProvider @{$prod}",
            "{$ro}App\\Admin\\Report-" => "dependency {$ro}App\\Admin\\Report @{$roModule}",
            "{$ro}App\\Advice-" => "dependency {$ro}App\\Advice @{$roModule}",
            "{$ro}App\\Replacement-" => "dependency {$ro}App\\Replacement @{$roModule}",
            "{$ro}App\\User-" => "dependency {$ro}App\\User @{$roModule}",
            "{$ro}Page\\Index-" => "dependency {$ro}Page\\Index @{$roModule}",
            // cli wraps prod wraps app: the outermost (first) segment wins.
            "{$s}ClockInterface-" => "dependency {$s}FrozenClock @{$a}CliModule",
            'BEAR\\AppMeta\\AbstractAppMeta-' => "provider {$pkg}Module\\AppMetaProvider @{$meta}",
            "BEAR\\AppMeta\\AbstractAppMeta-{$pkg}Annotation\\AsCompiled" => "object BEAR\\AppMeta\\Meta @{$meta}",
            "{$pkg}Module\\WriteDirs-" => "object {$pkg}Module\\WriteDirs @{$pkg}Module\\WriteModule",
            "{$pkg}Module\\WriteShape-" => "object {$pkg}Module\\WriteShape @{$pkg}Module\\WriteModule",
            "{$error}ErrorPageFactoryInterface-" => "dependency {$error}ProdVndErrorPageFactory @{$prod}",
            "{$error}NullPage-" => "dependency {$error}NullPage @{$pkg}Module\\ResourceObjectModule",
            'BEAR\\Resource\\RenderInterface-options'
                => "dependency {$pkg}Provide\\Transfer\\NullOptionsRenderer @{$prod}",
            // The AppMetaModule override beats the application's own AppInterface binding.
            'BEAR\\Sunday\\Extension\\Application\\AppInterface-' => "dependency {$a}App @{$meta}",
            'Psr\\Log\\LoggerInterface-' => "provider {$pkg}Provide\\Logger\\ProdMonologProvider @{$prod}",
            'Ray\\Aop\\MethodInvocation-' => "provider {$di}MethodInvocationProvider @{$di}AssistedModule",
            "{$di}AssistedInjectInterceptor-" => "dependency {$di}AssistedInjectInterceptor @{$di}AssistedInjectModule",
            "{$di}InjectorInterface-" => "object {$di}Injector @{$di}Injector",
            "{$di}MethodInvocationProvider-" => "dependency {$di}MethodInvocationProvider @{$di}AssistedModule",
            "{$di}MultiBinding\\Map-"
                => "provider {$di}MultiBinding\\MapProvider @{$di}MultiBinding\\MultiBindingModule",
            "{$di}MultiBinding\\MultiBindings-" => "object {$di}MultiBinding\\MultiBindings @-",
            "{$di}ProviderInterface-" => "provider {$di}ProviderSetProvider @{$di}ProviderSetModule",
        ], self::bindings($container));
        self::assertSame([
            // AppMetaModule's container absorbs the wrapped context chain.
            'bind BEAR\\AppMeta\\AbstractAppMeta-BEAR\\Package\\Annotation\\AsCompiled',
            'bind BEAR\\AppMeta\\AbstractAppMeta-',
            'bind BEAR\\Sunday\\Extension\\Application\\AppInterface-',
            'bind -BEAR\\Package\\Annotation\\AppName',
            // CliModule(ProdModule(AppModule(AssistedModule(WriteModule))))
            "bind {$s}ClockInterface-",
            'bind BEAR\\Package\\Provide\\Error\\ErrorPageFactoryInterface-',
            'bind Psr\\Log\\LoggerInterface-',
            'bind BEAR\\Resource\\RenderInterface-options',
            'bind -BEAR\\RepositoryModule\\Annotation\\CacheDir',
            'bind Psr\\Log\\LoggerInterface-',
            "bind {$s}ClockInterface-",
            'bind BEAR\\Sunday\\Extension\\Application\\AppInterface-',
            'bind Ray\\Di\\AssistedInjectInterceptor-',
            'bind Ray\\Aop\\MethodInvocation-',
            'bind Ray\\Di\\MethodInvocationProvider-',
            'bind BEAR\\Package\\Module\\WriteDirs-',
            'bind BEAR\\Package\\Module\\WriteShape-',
            'keep Psr\\Log\\LoggerInterface-',
            "keep {$s}ClockInterface-",
            'keep BEAR\\Sunday\\Extension\\Application\\AppInterface-',
            // ResourceObjectModule: psr4list order (shallow first), then NullPage.
            'bind Acme\\Shop\\Resource\\App\\Advice-',
            'bind Acme\\Shop\\Resource\\App\\Replacement-',
            'bind Acme\\Shop\\Resource\\App\\User-',
            'bind Acme\\Shop\\Resource\\Page\\Index-',
            'bind Acme\\Shop\\Resource\\App\\Admin\\Report-',
            'bind BEAR\\Package\\Provide\\Error\\NullPage-',
            // BuiltinModule: AssistedModule again (all keeps), ProviderSetModule, MultiBindingModule.
            'bind Ray\\Di\\AssistedInjectInterceptor-',
            'bind Ray\\Aop\\MethodInvocation-',
            'bind Ray\\Di\\MethodInvocationProvider-',
            'keep Ray\\Di\\AssistedInjectInterceptor-',
            'keep Ray\\Aop\\MethodInvocation-',
            'keep Ray\\Di\\MethodInvocationProvider-',
            'bind Ray\\Di\\ProviderInterface-',
            'bind Ray\\Di\\MultiBinding\\Map-',
            'bind Ray\\Di\\InjectorInterface-',
        ], self::events($container));
        // The application's CliModule is preferred over BEAR\Package\Context\CliModule.
        self::assertArrayNotHasKey('BEAR\\Package\\Context\\CliMarkerInterface-', $container->bindings);
        self::assertSame([], $this->interpreter->unknowns);
    }

    public function testUnresolvableContextSegmentIsRecordedAsUnknown(): void
    {
        // BEAR\Package\Module::installContextModule() throws InvalidContextException here.
        $container = (new BearPackageComposition($this->classes, $this->interpreter))('Acme\\Shop', 'nosuch-app');

        self::assertSame(
            ['context_module_not_found:nosuch'],
            array_column($this->interpreter->unknowns, 'reason'),
        );
        // The previously wrapped module is kept, so the app and write modules still contribute.
        self::assertArrayHasKey(self::SERVICE . 'ClockInterface-', $container->bindings);
        self::assertArrayHasKey('BEAR\\Package\\Module\\WriteDirs-', $container->bindings);
    }
}
