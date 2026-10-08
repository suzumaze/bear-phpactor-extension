<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\App;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Expression\BinaryExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\TernaryExpression;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Parser;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ParserNodes;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\PhpStringLiteral;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectReportPage;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/** Discovers contexts named in saved entry points; never chooses a deployment for the caller. */
final readonly class AppContextListQuery
{
    public function __construct(private int $maxFiles = 2000)
    {
    }

    /** @return SemanticResult<array<string, mixed>|null> */
    public function listInWorkspace(
        WorkspaceContext $workspace,
        ?string $contextPath = null,
        int $limit = 50,
        int $offset = 0,
    ): SemanticResult {
        if ($limit < 1 || $limit > 100 || $offset < 0) {
            return SemanticResult::invalidInput();
        }
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }
        $root = $project->value->root();
        $pending = array_map(
            static fn (string $dir): string => $root . '/' . $dir,
            ['public', 'bin', 'bootstrap', 'tests', 'src'],
        );
        foreach ($project->value->psr4() as $directories) {
            foreach ($directories as $directory) {
                $pending[] = str_starts_with($directory, '/') ? $directory : $root . '/' . $directory;
            }
        }
        sort($pending);
        $seen = [];
        $contexts = [];
        $unresolved = [];
        $files = 0;
        $skipped = 0;
        $visits = 0;
        $parser = new Parser();
        $scannedFiles = [];
        $forwarders = new ContextForwarderIndex($workspace->accessPolicy());
        while ($pending !== [] && $files < $this->maxFiles && $visits < 20000) {
            ++$visits;
            $candidate = array_shift($pending);
            $path = $workspace->accessPolicy()->inspectExisting($candidate);
            if ($path->value === null || isset($seen[$path->value->absolute])) {
                continue;
            }
            $file = $path->value->absolute;
            $seen[$file] = true;
            if (is_dir($file)) {
                foreach (scandir($file) ?: [] as $child) {
                    if (!in_array($child, ['.', '..', 'vendor', '.git', 'node_modules'], true)) {
                        $pending[] = $file . '/' . $child;
                    }
                }
                continue;
            }
            if (!is_file($file) || !in_array(pathinfo($file, PATHINFO_EXTENSION), ['php', ''], true)) {
                continue;
            }
            ++$files;
            $scannedFiles[] = [$file, $path->value->relative];
            $contents = @file_get_contents($file, false, null, 0, 1_048_577);
            if ($contents === false || strlen($contents) > 1_048_576) {
                ++$skipped;
                continue;
            }
            $parsed = $parser->parseSourceFile($contents, $file);
            // A file named by `(require <path>)(...)` is scanned even when it sits outside the usual roots.
            foreach ($forwarders->collectFile($parsed, $file) as $required) {
                $pending[] = $required;
            }
            foreach ($parsed->getDescendantNodes() as $node) {
                if (!$node instanceof CallExpression || !ContextCalls::isContextCall($node, $contents)) {
                    continue;
                }
                $argument = ContextCalls::contextArgument($node, $contents);
                $site = [
                    'path' => $path->value->relative,
                    'line' => substr_count($contents, "\n", 0, $node->getStartPosition()) + 1,
                ];
                if ($argument === null) {
                    $unresolved[] = $site + ['reason' => 'context_argument_missing'];
                    continue;
                }
                $this->collect($argument, $site, $file, $forwarders, $contexts, $unresolved);
            }
        }
        $forwarders->resolve();
        if ($forwarders->hasForwarders()) {
            $this->collectForwarderCalls($scannedFiles, $forwarders, $workspace, $parser, $contexts, $unresolved);
        }
        $unresolved = $this->withoutAnsweredSites($unresolved, $forwarders);
        ksort($contexts);
        $items = [];
        foreach ($contexts as $context => $sources) {
            $items[] = [
                'applicationContext' => $context,
                'sources' => array_slice($sources, 0, 100),
                'sourceTotal' => count($sources),
                'sourcesTruncated' => count($sources) > 100,
            ];
        }
        $page = ProjectReportPage::slice($items, $offset, $limit, ProjectReportPage::serializedBytes(...));

        return SemanticResult::ok([
            'items' => $page,
            'total' => count($items),
            'offset' => $offset,
            'truncated' => $offset + count($page) < count($items),
            'selectedContext' => null,
            'scannedFiles' => $files,
            'skippedFiles' => $skipped,
            'scanTruncated' => $pending !== [],
            'unresolved' => array_slice($unresolved, 0, 100),
            'unresolvedTotal' => count($unresolved),
            'unresolvedTruncated' => count($unresolved) > 100,
            'coverage' => [
                'basis' => 'source-declared-context-candidates',
                'runtimeUsageObserved' => false,
                'supportedCalls' => [
                    'BEAR\\Package\\Compiler\\Bootstrap::__invoke',
                    'BEAR\\Package\\Injector::getInstance',
                    'BEAR\\Package\\Injector::getOverrideInstance',
                ],
                'variableAssignmentsResolved' => false,
                'wrapperResolution' => 'positional-parameter-forwarders',
                'forwarderForms' => [
                    'static-method',
                    'instance-method',
                    'closure-returned-by-required-file',
                    'closure-variable',
                ],
                'overrideModulesApplied' => false,
            ],
        ], [Provenance::derived()]);
    }

    /**
     * @param list<array{0: string, 1: string}> $files
     * @param array<string, list<array{path: string, line: int}>> $contexts
     * @param list<array<string, mixed>> $unresolved
     */
    private function collectForwarderCalls(
        array $files,
        ContextForwarderIndex $forwarders,
        WorkspaceContext $workspace,
        Parser $parser,
        array &$contexts,
        array &$unresolved,
    ): void {
        foreach ($files as [$file, $relative]) {
            $path = $workspace->accessPolicy()->inspectExisting($file);
            if ($path->value === null) {
                continue;
            }
            $contents = @file_get_contents($file, false, null, 0, 1_048_577);
            if ($contents === false || strlen($contents) > 1_048_576) {
                continue;
            }
            $parsed = $parser->parseSourceFile($contents, $file);
            foreach ($parsed->getDescendantNodes() as $node) {
                if (!$node instanceof CallExpression || ContextCalls::isContextCall($node, $contents)) {
                    continue;
                }
                $key = ContextCallee::key($node, $file, $workspace->accessPolicy());
                $position = $key === null ? null : $forwarders->contextPosition($key);
                if ($key === null || $position === null) {
                    continue;
                }
                $forwarders->noteCall($key);
                $argument = ContextCalls::positionalArguments($node, $contents)[$position] ?? null;
                $site = [
                    'path' => $relative,
                    'line' => substr_count($contents, "\n", 0, $node->getStartPosition()) + 1,
                ];
                if ($argument === null) {
                    $unresolved[] = $site + ['reason' => 'wrapper_context_argument_unresolved'];
                    continue;
                }
                $this->collect($argument, $site, $file, $forwarders, $contexts, $unresolved);
            }
        }
    }

    /**
     * Drops the inner `Injector::getInstance($context)` of a forwarder once its callers were seen:
     * the callers' own sites carry the answer, resolved or listed as unresolved themselves.
     *
     * @param list<array<string, mixed>> $unresolved
     * @return list<array{path: string, line: int, reason: string}>
     */
    private function withoutAnsweredSites(array $unresolved, ContextForwarderIndex $forwarders): array
    {
        $remaining = [];
        foreach ($unresolved as $site) {
            $enclosing = $site['enclosing'] ?? null;
            if (is_array($enclosing) && $forwarders->isAnsweredByCallers($enclosing)) {
                continue;
            }
            $remaining[] = ['path' => $site['path'], 'line' => $site['line'], 'reason' => $site['reason']];
        }

        return $remaining;
    }

    /**
     * @param array{path: string, line: int} $site
     * @param array<string, list<array{path: string, line: int}>> $contexts
     * @param list<array<string, mixed>> $unresolved
     */
    private function collect(
        Node $argument,
        array $site,
        string $file,
        ContextForwarderIndex $forwarders,
        array &$contexts,
        array &$unresolved,
    ): void {
        $argument = ContextCalls::unwrap($argument);
        if (
            $argument instanceof StringLiteral && !array_filter(
                is_array($argument->children) ? $argument->children : [],
                static fn (mixed $part): bool => $part instanceof Node,
            )
        ) {
            $value = PhpStringLiteral::decode($argument);
            if (preg_match('/^[A-Za-z0-9_]+(?:-[A-Za-z0-9_]+)*$/', $value) === 1) {
                $contexts[$value] ??= [];
                if (!in_array($site, $contexts[$value], true)) {
                    $contexts[$value][] = $site;
                }
                return;
            }
        }
        if ($argument instanceof TernaryExpression) {
            $trueValue = ParserNodes::optional($argument->ifExpression) ?? $argument->condition;
            $this->collect($trueValue, $site, $file, $forwarders, $contexts, $unresolved);
            $this->collect($argument->elseExpression, $site, $file, $forwarders, $contexts, $unresolved);
            return;
        }
        if (
            $argument instanceof BinaryExpression
            && $argument->operator->getText($argument->getFileContents()) === '??'
        ) {
            $this->collect($argument->leftOperand, $site, $file, $forwarders, $contexts, $unresolved);
            $this->collect($argument->rightOperand, $site, $file, $forwarders, $contexts, $unresolved);
            return;
        }
        $enclosing = $forwarders->enclosingCallable($argument, $file);
        $unresolved[] = $site + ['reason' => 'context_expression_unresolved']
            + ($enclosing === null ? [] : ['enclosing' => $enclosing]);
    }
}
