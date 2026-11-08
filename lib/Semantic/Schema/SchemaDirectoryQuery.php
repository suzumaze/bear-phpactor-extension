<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Schema;

use Suzumaze\BearPhpactor\Semantic\App\AppContextListQuery;
use Suzumaze\BearPhpactor\Semantic\App\ContextDiscoveryTrace;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\BearPackageComposition;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSourceIndex;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ModuleInterpreter;
use Suzumaze\BearPhpactor\Semantic\Di\DiContainerQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\SourceSnapshot;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Util\PathGuard;

/**
 * Reads only the directory bindings a Resource's files are found by: the two schema directories and
 * Qiq's template directory. Application code is never executed.
 *
 * Qiq is installed only by the contexts that render HTML, so for `qiq` a context that never binds the
 * directory is left out instead of counting as a disagreement.
 *
 * Discovering the contexts and composing each one takes seconds on a large application, so the
 * answer is kept for the life of the process and reused while what it read is unchanged. No file
 * watcher is involved, so headless callers get the same guarantee: every reuse first checks the
 * files the answer was read from (a `stat()` each) and the directories that were listed. Files
 * that changed but cannot affect the answer, such as a saved Resource, are read alone and do not
 * cost a new discovery ({@see ContextDiscoveryTrace}); the composition reads no Resource file.
 */
final class SchemaDirectoryQuery
{
    private const SHARED_ENTRIES = 16;

    /** @var array<string, array<string, mixed>> Request-local compositions, shared across methods and kinds. */
    private array $cache = [];

    /**
     * Process-wide answers, least recently used first.
     *
     * @var array<string, array{
     *     discovery: ?array{contexts: list<string>, complete: bool, selection: string, coverage: array<string, mixed>},
     *     trace: ?ContextDiscoveryTrace,
     *     inputs: SourceSnapshot,
     *     result: array<string, mixed>,
     * }>
     */
    private static array $shared = [];

    /** For tests: forget every answer kept across requests. */
    public static function forgetSharedAnswers(): void
    {
        self::$shared = [];
    }

    /** @return array<string, mixed> */
    public function inspect(WorkspaceContext $workspace, ?string $resourceFile, string $kind, ?string $context): array
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
            if ($kind === 'qiq' && $binding['status'] === 'not_declared') {
                continue;
            }
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
        ?string $resourceFile,
        array $psr4,
        ?string $context,
    ): array {
        $key = $workspace->root() . "\0" . $root . "\0" . ($context ?? '');
        $entry = self::$shared[$key] ?? null;
        unset(self::$shared[$key]);
        $discovery = null;
        $trace = null;
        if ($entry !== null) {
            $trace = $entry['trace'];
            $discovery = $trace === null
                || $trace->stillAnswers($workspace->accessPolicy(), AppContextListQuery::MAX_FILES)
                ? $entry['discovery'] : null;
            if ($discovery !== null || $entry['discovery'] === null) {
                if ($entry['inputs']->unchanged()) {
                    self::$shared[$key] = $entry;

                    return $entry['result'];
                }
            }
        }
        $classes = new ClassSourceIndex($root, $workspace->root());
        $app = DiContainerQuery::appName($psr4, $root);
        foreach ($psr4 as $dirs) {
            foreach ($dirs as $dir) {
                $base = str_starts_with($dir, '/') ? $dir : rtrim($root, '/') . '/' . $dir;
                $classes->inputs()->probe(rtrim($base, '/') . '/Module/AppModule.php');
            }
        }
        $cacheable = true;
        if ($app !== null && ($context !== null || $classes->find($app . '\\Module\\AppModule') !== null)) {
            if ($discovery === null && $context === null) {
                $trace = new ContextDiscoveryTrace();
                $discovery = $this->discover($workspace, $resourceFile, $trace);
                $cacheable = $trace->complete();
            }
            $result = $this->composeContexts($workspace, $classes, $app, $context, $discovery);
        } else {
            $trace = null;
            $discovery = null;
            $result = ['contexts' => [], 'complete' => true, 'compositionComplete' => true,
                'provisional' => false, 'selection' => 'no_app_module', 'discovery' => self::coverage()];
        }
        if ($cacheable) {
            self::$shared[$key] = [
                'discovery' => $discovery,
                'trace' => $context === null ? $trace : null,
                'inputs' => $classes->inputs(),
                'result' => $result,
            ];
            while (count(self::$shared) > self::SHARED_ENTRIES) {
                unset(self::$shared[array_key_first(self::$shared)]);
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private static function coverage(): array
    {
        return [
            'discoveredTotal' => 0, 'evaluated' => 0, 'limit' => 20, 'truncated' => false,
            'scanTruncated' => false, 'unresolvedTotal' => 0, 'skippedFiles' => 0,
        ];
    }

    /** @return array{contexts: list<string>, complete: bool, selection: string, coverage: array<string, mixed>} */
    private function discover(WorkspaceContext $workspace, ?string $resourceFile, ContextDiscoveryTrace $trace): array
    {
        $result = (new AppContextListQuery())->listInWorkspace($workspace, $resourceFile, limit: 100, trace: $trace);
        $data = $result->value;
        $contexts = array_column($data['items'] ?? [], 'applicationContext');
        $complete = $data !== null && !$data['truncated'] && !$data['scanTruncated']
            && $data['skippedFiles'] === 0 && $data['unresolvedTotal'] === 0 && count($contexts) <= 20;
        $coverage = [
            'discoveredTotal' => $data['total'] ?? 0,
            'evaluated' => 0,
            'limit' => 20,
            'truncated' => ($data['truncated'] ?? false) || count($contexts) > 20,
            'scanTruncated' => $data['scanTruncated'] ?? true,
            'unresolvedTotal' => $data['unresolvedTotal'] ?? 0,
            'skippedFiles' => $data['skippedFiles'] ?? 0,
        ];

        return [
            'contexts' => $contexts === [] ? ['app'] : array_slice($contexts, 0, 20),
            'complete' => $complete,
            'selection' => $contexts === [] ? 'app_module_only' : 'discovered_candidates',
            'coverage' => $coverage,
        ];
    }

    /**
     * @param array{
     *     contexts: list<string>, complete: bool, selection: string, coverage: array<string, mixed>,
     * }|null $discovery null for an explicit context
     * @return array<string, mixed>
     */
    private function composeContexts(
        WorkspaceContext $workspace,
        ClassSourceIndex $classes,
        string $app,
        ?string $context,
        ?array $discovery,
    ): array {
        if ($discovery === null) {
            $contexts = [(string) $context];
            $complete = true;
            $selection = 'explicit';
            $discoveryCoverage = ['discoveredTotal' => 1] + self::coverage();
        } else {
            ['contexts' => $contexts, 'complete' => $complete, 'selection' => $selection,
                'coverage' => $discoveryCoverage] = $discovery;
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
            $qualifiers = [
                'response' => 'json_schema_dir',
                'request' => 'json_validate_dir',
                'qiq' => 'qiq_template_dir',
            ];
            foreach ($qualifiers as $kind => $qualifier) {
                $index = '-' . $qualifier;
                $dependency = $container->bindings[$index] ?? null;
                $directory = $dependency['schemaDirectory'] ?? null;
                $status = $dependency === null ? 'not_declared' : 'dynamic';
                $relative = null;
                if (is_string($directory)) {
                    $status = $directory === '' ? 'empty' : 'relative_path';
                    if ($directory !== '' && PathGuard::isAbsolutePath($directory)) {
                        $classes->inputs()->isDirectory($directory);
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
