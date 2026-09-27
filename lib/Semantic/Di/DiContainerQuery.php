<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di;

use Suzumaze\BearPhpactor\Semantic\Di\Composition\BearPackageComposition;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSourceIndex;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ModuleInterpreter;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/**
 * Composes the Ray.Di container a BEAR.Package context would build, from saved source.
 *
 * Application and vendor modules are interpreted, never executed: no module,
 * provider, autoloader, or application code runs. Values that exist only at runtime
 * stay unknown, and branches that depend on them are reported instead of guessed.
 */
final readonly class DiContainerQuery
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 100;

    /**
     * @param array<mixed>|null $environment explicit environment profile, see ModuleInterpreter
     * @return SemanticResult<DiContainerComposition|null>
     */
    public function composeInWorkspace(
        WorkspaceContext $workspace,
        string $applicationContext,
        ?string $contextPath = null,
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0,
        ?array $environment = null,
    ): SemanticResult {
        if (
            !self::isEnvironment($environment)
            || $limit < 1
            || $limit > self::MAX_LIMIT
            || $offset < 0
            || preg_match('/^[A-Za-z0-9_]+(?:-[A-Za-z0-9_]+)*$/', $applicationContext) !== 1
        ) {
            return SemanticResult::invalidInput();
        }
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }
        $root = $project->value->root();
        $appName = self::appName($project->value->psr4(), $root);
        if ($appName === null) {
            return SemanticResult::unsupported();
        }
        $classes = new ClassSourceIndex($root, $workspace->root());
        $interpreter = new ModuleInterpreter($classes, $environment);
        $container = (new BearPackageComposition($classes, $interpreter))($appName, $applicationContext);

        $items = [];
        foreach ($container->bindings as $index => $dependency) {
            [$type, $name] = explode('-', $index, 2) + [1 => ''];
            $kind = $dependency['kind'] === 'dependency' && $dependency['target'] === $type && $name === ''
                ? 'untargeted'
                : $dependency['kind'];
            $items[] = [
                'index' => $index,
                'type' => $type,
                'name' => $name,
                'kind' => $kind,
                'target' => $dependency['target'],
                'module' => $container->sources[$index] ?? null,
            ];
        }
        // Ray.Di sorts the container before it is used.
        usort($items, static fn (array $a, array $b): int => strcmp($a['index'], $b['index']));
        $events = ['bind' => 0, 'replace' => 0, 'keep' => 0, 'move' => 0];
        foreach ($container->events as $event) {
            ++$events[$event['type']];
        }
        $modules = array_count_values(array_values($container->sources));
        ksort($modules);

        return SemanticResult::ok(new DiContainerComposition(
            $applicationContext,
            $appName,
            array_slice($items, $offset, $limit),
            count($items),
            $offset,
            $offset + $limit < count($items),
            $events,
            $modules,
            $interpreter->unknowns,
        ), [Provenance::derived()]);
    }

    /**
     * An environment profile maps variable names to string values; unlisted variables are unset.
     *
     * @phpstan-assert-if-true array<string, string>|null $environment
     */
    public static function isEnvironment(?array $environment): bool
    {
        foreach ($environment ?? [] as $name => $value) {
            if (!is_string($name) || $name === '' || !is_string($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The BEAR application name is the psr-4 namespace that owns `Module\AppModule`.
     *
     * @param array<string, list<string>> $psr4
     */
    public static function appName(array $psr4, string $root): ?string
    {
        foreach ($psr4 as $prefix => $dirs) {
            foreach ($dirs as $dir) {
                $base = str_starts_with($dir, '/') ? $dir : rtrim($root, '/') . '/' . $dir;
                if (is_file(rtrim($base, '/') . '/Module/AppModule.php')) {
                    return rtrim($prefix, '\\');
                }
            }
        }

        return null;
    }
}
