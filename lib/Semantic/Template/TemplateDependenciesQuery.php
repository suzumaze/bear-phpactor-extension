<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Template;

use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Template\TwigDependencyScanner;

/** Walks saved template references without rendering templates or invoking Resources. */
final class TemplateDependenciesQuery
{
    private const MAX_FILES = 100;
    private const MAX_DEPTH = 20;
    private const MAX_EDGES = 1000;
    private const MAX_FILE_BYTES = 262144;
    private const MAX_TOTAL_BYTES = 2097152;

    /** @return array<string, mixed> */
    public function inspect(WorkspaceContext $workspace, ResourceTemplateResolution $entry): array
    {
        $graph = [
            'available' => $entry->engine === 'twig' && $entry->templateFile !== null,
            'scope' => 'supported_twig_source_references', 'runtimeObserved' => false,
            'resourceExpansion' => false, 'complete' => true, 'truncated' => false,
            'files' => [], 'edges' => [], 'resources' => [], 'unresolved' => [],
            'limits' => ['files' => self::MAX_FILES, 'depth' => self::MAX_DEPTH, 'edges' => self::MAX_EDGES,
                'fileBytes' => self::MAX_FILE_BYTES, 'totalBytes' => self::MAX_TOTAL_BYTES],
        ];
        if (!$graph['available']) {
            $graph['complete'] = false;
            $graph['unresolved'][] = ['reason' => 'twig_entry_template_required'];
            return $graph;
        }
        $visited = [];
        $bytes = 0;
        $walk = function (
            string $file,
            array $ancestors,
            int $depth
        ) use (
            &$walk,
            &$graph,
            &$visited,
            &$bytes,
            $workspace,
        ): void {
            if (isset($visited[$file])) {
                return;
            }
            $checked = $workspace->accessPolicy()->inspectExisting($file);
            if ($checked->value === null) {
                $graph['complete'] = false;
                $graph['unresolved'][] = ['reason' => $checked->status->value];
                return;
            }
            $sourcePath = $checked->value->relative;
            if (count($visited) >= self::MAX_FILES || $depth > self::MAX_DEPTH) {
                $graph['complete'] = false;
                $graph['truncated'] = true;
                $graph['unresolved'][] = ['path' => $sourcePath, 'reason' => 'file_or_depth_limit'];
                return;
            }
            $visited[$file] = true;
            $text = @file_get_contents($file, false, null, 0, self::MAX_FILE_BYTES + 1);
            if (
                $text === false || strlen($text) > self::MAX_FILE_BYTES
                || $bytes + strlen($text) > self::MAX_TOTAL_BYTES
            ) {
                $graph['complete'] = false;
                $graph['truncated'] = $graph['truncated'] || $text !== false;
                $graph['unresolved'][] = ['path' => $sourcePath, 'reason' => 'unreadable_or_byte_limit'];
                return;
            }
            $bytes += strlen($text);
            $graph['files'][] = ['path' => $sourcePath, 'freshness' => 'saved'];
            $scan = (new TwigDependencyScanner())->scan($text);
            if (!$scan['complete']) {
                $graph['complete'] = false;
                $graph['unresolved'][] = ['path' => $sourcePath, 'reason' => 'unbalanced_template_syntax'];
            }
            $ancestors[] = $file;
            foreach ($scan['references'] as $reference) {
                if (count($graph['edges']) + count($graph['resources']) >= self::MAX_EDGES) {
                    $graph['complete'] = false;
                    $graph['truncated'] = true;
                    $graph['unresolved'][] = ['path' => $sourcePath, 'reason' => 'edge_limit'];
                    break;
                }
                $edge = ['source' => $sourcePath, ...$reference];
                if ($edge['kind'] === 'resource') {
                    $graph['resources'][] = $edge;
                    if ($edge['status'] === 'dynamic') {
                        $graph['complete'] = false;
                    }
                    continue;
                }
                $target = null;
                if ($edge['status'] === 'static') {
                    $resolution = (new TemplateQuery())->resolveInWorkspace(
                        $workspace,
                        'twig',
                        $edge['name'],
                        $sourcePath,
                    );
                    $edge['status'] = $resolution->status->value;
                    if ($resolution->value !== null) {
                        $target = $resolution->value->file;
                        $edge['target'] = $workspace->accessPolicy()->inspectExisting($target)->value?->relative;
                        if (in_array($target, $ancestors, true)) {
                            $edge['status'] = 'cycle';
                        }
                    }
                }
                if ($edge['status'] !== 'ok') {
                    $graph['complete'] = false;
                }
                $graph['edges'][] = $edge;
                if ($target !== null && $edge['status'] === 'ok' && $edge['kind'] !== 'source') {
                    $walk($target, $ancestors, $depth + 1);
                }
            }
        };
        $walk($entry->templateFile, [], 0);
        $graph['scannedBytes'] = $bytes;

        return $graph;
    }
}
