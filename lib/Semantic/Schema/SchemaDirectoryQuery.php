<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Schema;

use Suzumaze\BearPhpactor\Semantic\App\AppContextListQuery;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\BearPackageComposition;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSourceIndex;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ModuleInterpreter;
use Suzumaze\BearPhpactor\Semantic\Di\DiContainerQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Util\PathGuard;

/** Reads only the two schema directory bindings; application code is never executed. */
final class SchemaDirectoryQuery
{
    /** @var array<string, array<string, mixed>> Request-local compositions, shared across methods and kinds. */
    private array $cache = [];

    /** @return array<string, mixed> */
    public function inspect(WorkspaceContext $workspace, string $resourceFile, string $kind, ?string $context): array
    {
        $project = $workspace->project($resourceFile)->value;
        if ($project === null) {
            return ['status' => 'unsupported', 'candidates' => []];
        }
        $root = $project->root();
        $key = $root . ':' . ($context ?? '');
        $all = $this->cache[$key] ??= $this->compose($workspace, $root, $resourceFile, $project->psr4(), $context);
        $candidates = [];
        foreach ($all['contexts'] as $name => $bindings) {
            $binding = $bindings[$kind];
            $candidates[] = ['applicationContext' => $name, ...$binding];
        }
        $states = array_unique(array_column($candidates, 'status'));
        $status = 'configured';
        if ($candidates === [] || $states === ['not_declared']) {
            $status = 'convention';
        } elseif (in_array('outside_workspace', $states, true)) {
            $status = 'outside_workspace';
        } elseif (array_diff($states, ['configured']) !== []) {
            $status = 'unsupported';
        }
        $consensus = $status === 'configured'
            && count(array_unique(array_column($candidates, 'directory'))) === 1;
        if (
            (!$all['complete'] && !$consensus) || !$all['compositionComplete']
            || ($status === 'convention' && $all['provisional'])
        ) {
            $status = 'unsupported';
        }

        return [
            'status' => $status,
            'candidates' => $candidates,
            'contextSelection' => $all['selection'],
            'contextDiscoveryComplete' => $all['complete'],
            'provisional' => $all['provisional'] || !$all['complete'],
            'contextDiscovery' => $all['discovery'],
            'appDirAssumption' => 'composer_project_root',
            'runtimeObserved' => false,
        ];
    }

    /**
     * @param array<string, mixed> $selection
     * @return array<string, mixed>
     */
    public static function summary(array $selection): array
    {
        if ($selection === []) {
            return [];
        }

        return array_diff_key($selection, ['candidates' => true])
            + ['candidateCount' => count($selection['candidates'] ?? [])];
    }

    /**
     * @param array<string, list<string>> $psr4
     * @return array<string, mixed>
     */
    private function compose(
        WorkspaceContext $workspace,
        string $root,
        string $resourceFile,
        array $psr4,
        ?string $context,
    ): array {
        $classes = new ClassSourceIndex($root, $workspace->root());
        $app = DiContainerQuery::appName($psr4, $root);
        $discoveryCoverage = [
            'discoveredTotal' => 0, 'evaluated' => 0, 'limit' => 20, 'truncated' => false,
            'scanTruncated' => false, 'unresolvedTotal' => 0, 'skippedFiles' => 0,
        ];
        $empty = ['contexts' => [], 'complete' => true, 'compositionComplete' => true,
            'provisional' => false, 'selection' => 'no_app_module', 'discovery' => $discoveryCoverage];
        if ($app === null || ($context === null && $classes->find($app . '\\Module\\AppModule') === null)) {
            return $empty;
        }
        $complete = true;
        $selection = 'explicit';
        if ($context === null) {
            $discovery = (new AppContextListQuery())->listInWorkspace($workspace, $resourceFile, limit: 100);
            $data = $discovery->value;
            $contexts = array_column($data['items'] ?? [], 'applicationContext');
            $complete = $data !== null && !$data['truncated'] && !$data['scanTruncated']
                && $data['skippedFiles'] === 0 && $data['unresolvedTotal'] === 0 && count($contexts) <= 20;
            $discoveryCoverage = [
                'discoveredTotal' => $data['total'] ?? 0,
                'evaluated' => 0,
                'limit' => 20,
                'truncated' => ($data['truncated'] ?? false) || count($contexts) > 20,
                'scanTruncated' => $data['scanTruncated'] ?? true,
                'unresolvedTotal' => $data['unresolvedTotal'] ?? 0,
                'skippedFiles' => $data['skippedFiles'] ?? 0,
            ];
            $selection = $contexts === [] ? 'app_module_only' : 'discovered_candidates';
            $contexts = $contexts === [] ? ['app'] : array_slice($contexts, 0, 20);
        } else {
            $contexts = [$context];
            $discoveryCoverage['discoveredTotal'] = 1;
        }
        $discoveryCoverage['evaluated'] = count($contexts);
        $result = ['contexts' => [], 'complete' => $complete, 'compositionComplete' => true,
            'provisional' => false, 'selection' => $selection, 'discovery' => $discoveryCoverage];
        foreach ($contexts as $name) {
            $interpreter = new ModuleInterpreter($classes, captureSchemaDirectories: true);
            $container = (new BearPackageComposition($classes, $interpreter))($app, $name);
            $result['provisional'] = $result['provisional'] || $interpreter->unknowns !== [];
            foreach ($interpreter->unknowns as $unknown) {
                if (str_starts_with($unknown['reason'], 'context_module_not_found:')) {
                    $result['complete'] = false;
                    $result['compositionComplete'] = false;
                }
            }
            foreach (['response' => 'json_schema_dir', 'request' => 'json_validate_dir'] as $kind => $qualifier) {
                $index = '-' . $qualifier;
                $dependency = $container->bindings[$index] ?? null;
                $directory = $dependency['schemaDirectory'] ?? null;
                $status = $dependency === null ? 'not_declared' : 'dynamic';
                $relative = null;
                if (is_string($directory)) {
                    $status = $directory === '' ? 'empty' : 'relative_path';
                    if ($directory !== '' && PathGuard::isAbsolutePath($directory)) {
                        $checked = $workspace->accessPolicy()->inspectExisting($directory);
                        $status = $checked->status->value;
                        if ($checked->value !== null && is_dir($checked->value->absolute)) {
                            $status = 'configured';
                            $relative = $checked->value->relative;
                        } elseif (!str_starts_with($directory, rtrim($workspace->root(), '/') . '/')) {
                            $status = 'outside_workspace';
                        }
                    }
                }
                $origin = $container->origins[$index] ?? null;
                $result['contexts'][$name][$kind] = [
                    'status' => $status,
                    'directory' => $relative,
                    'origin' => $origin === null ? null : [
                        'module' => $origin->module, 'path' => $origin->path, 'line' => $origin->line,
                    ],
                ];
            }
        }

        return $result;
    }
}
