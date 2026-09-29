<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di\Composition;

/**
 * The composition root BEAR.Package and Ray.Di run before an injector exists.
 *
 * Mirrors, in order, `BEAR\Package\Module::__invoke()`,
 * `BEAR\Package\Injector\PackageInjector::module()`, and the injector-time steps of
 * `Ray\Di\Injector::__construct()` (BuiltinModule, CompileNullObject, and the
 * InjectorInterface binding). These steps are fixed recipes, not interpreted source.
 */
final readonly class BearPackageComposition
{
    private const PACKAGE_MODULE = 'BEAR\\Package\\Module';

    public const RECIPE_STEPS = [
        'bear_package_module_invoke',
        'package_injector_resource_object_module',
        'ray_di_builtin_module',
        'ray_di_injector_self_binding',
    ];

    public function __construct(private ClassSourceIndex $classes, private ModuleInterpreter $interpreter)
    {
    }

    /**
     * @param bool $injector false stops at PackageInjector::module(), the module an
     *                       object grapher is given; true continues through Injector construction
     */
    public function __invoke(string $appName, string $context, bool $injector = true): EmulatedContainer
    {
        $appMeta = $this->interpreter->newAppMeta($appName, $context, rtrim($this->classes->root(), '/'));
        $module = $this->packageModule($appMeta, $context) ?? $this->recipeModule($appName, $context, $appMeta);
        // PackageInjector::module()
        $this->install($module, $this->interpreter->newObject('BEAR\\Package\\Module\\ResourceObjectModule', [
            $this->interpreter->callMethod($appMeta, 'getResourceListGenerator', [], null),
        ]));
        if (!$injector) {
            return $this->interpreter->containerOf($module);
        }
        // Ray\Di\BuiltinModule
        $builtins = [
            'Ray\\Di\\AssistedModule',
            'Ray\\Di\\ProviderSetModule',
            'Ray\\Di\\MultiBinding\\MultiBindingModule',
        ];
        foreach ($builtins as $builtin) {
            $this->install($module, $this->interpreter->newObject($builtin));
        }
        $container = $this->interpreter->containerOf($module);
        // Injector::__construct(): (new Bind($container, InjectorInterface::class, self::class))->toInstance($this)
        $container->add(
            'Ray\\Di\\InjectorInterface-',
            ['kind' => 'object', 'target' => 'Ray\\Di\\Injector'],
            'Ray\\Di\\Injector',
        );

        return $container;
    }

    /**
     * Interprets the installed `BEAR\\Package\\Module::__invoke()`, so each BEAR.Package version
     * composes contexts the way its own source does.
     */
    private function packageModule(ObjectValue $appMeta, string $context): ?ObjectValue
    {
        $source = $this->classes->find(self::PACKAGE_MODULE);
        if ($source === null || $source->method('__invoke') === null) {
            return null;
        }
        $package = $this->interpreter->newObject(self::PACKAGE_MODULE);
        $module = $package instanceof ObjectValue
            ? $this->interpreter->callMethod($package, '__invoke', [$appMeta, $context], null)
            : null;
        if (
            $module instanceof ObjectValue
            && $this->interpreter->isSubclassOf($module->class, ModuleInterpreter::ABSTRACT_MODULE)
        ) {
            return $module;
        }
        // BEAR.Package throws InvalidContextException for an unknown context segment.
        $this->interpreter->callerUnknown('bear_package_module_unresolved:' . $context, null, $appMeta);

        return new ObjectValue('Ray\\Di\\AssistedModule');
    }

    /**
     * The BEAR.Package 1.2x composition, used when its Module class is not installed.
     */
    private function recipeModule(string $appName, string $context, ObjectValue $appMeta): ObjectValue
    {
        $module = $this->interpreter->newObject('Ray\\Di\\AssistedModule', [
            $this->interpreter->newObject('BEAR\\Package\\Module\\WriteModule', [$appMeta, $context]),
        ]);
        assert($module instanceof ObjectValue);
        foreach (array_reverse(explode('-', $context)) as $segment) {
            $module = $this->contextModule($appName, $segment, $appMeta, $module);
        }
        $this->override($module, $this->interpreter->newObject('BEAR\\Package\\Module\\AppMetaModule', [$appMeta]));

        return $module;
    }

    private function contextModule(
        string $appName,
        string $segment,
        ObjectValue $appMeta,
        ObjectValue $module,
    ): ObjectValue {
        $class = $appName . '\\Module\\' . ucwords($segment) . 'Module';
        $source = $this->classes->find($class);
        if ($source === null || !$source->isClass()) {
            $class = 'BEAR\\Package\\Context\\' . ucwords($segment) . 'Module';
        }
        if (!$this->interpreter->isSubclassOf($class, ModuleInterpreter::ABSTRACT_MODULE)) {
            // BEAR.Package throws InvalidContextException here; keep what was composed so far.
            $this->interpreter->callerUnknown('context_module_not_found:' . $segment, null, $module);

            return $module;
        }
        $isAppModule = $this->interpreter->isSubclassOf($class, 'BEAR\\Package\\AbstractAppModule');
        $created = $this->interpreter->newObject($class, $isAppModule ? [$appMeta, $module] : [$module]);

        return $created instanceof ObjectValue ? $created : $module;
    }

    private function install(ObjectValue $module, mixed $installed): void
    {
        if ($installed instanceof ObjectValue) {
            $this->interpreter->containerOf($module)->merge(
                $this->interpreter->containerOf($installed),
                new ModuleEdge('recipe_install', $module->class, $installed->class),
            );
        }
    }

    private function override(ObjectValue $module, mixed $override): void
    {
        if ($override instanceof ObjectValue) {
            $container = $this->interpreter->containerOf($override);
            $container->traceThrough(new ModuleEdge('recipe_override', $module->class, $override->class));
            $container->merge($this->interpreter->containerOf($module), reason: 'overridden');
            $module->container = $container;
        }
    }
}
