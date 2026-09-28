<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\App;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\Expression\BinaryExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\ObjectCreationExpression;
use Microsoft\PhpParser\Node\Expression\ParenthesizedExpression;
use Microsoft\PhpParser\Node\Expression\ScopedPropertyAccessExpression;
use Microsoft\PhpParser\Node\Expression\TernaryExpression;
use Microsoft\PhpParser\Node\Expression\Variable;
use Microsoft\PhpParser\Node\MethodDeclaration;
use Microsoft\PhpParser\Node\Parameter;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Node\Statement\CompoundStatementNode;
use Microsoft\PhpParser\Node\Statement\ReturnStatement;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Parser;
use Microsoft\PhpParser\Token;
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
        $pending = array_map(static fn (string $dir): string => $root . '/' . $dir, ['public', 'bin', 'tests', 'src']);
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
        $forwarders = [];
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
            foreach ($parsed->getDescendantNodes() as $node) {
                if ($node instanceof MethodDeclaration) {
                    $forwarder = $this->returnOnlyInjectorForwarder($node, $contents);
                    if ($forwarder !== null) {
                        $forwarders[$forwarder['key']] = $forwarder['contextParameter'];
                    }
                }
                if (!$node instanceof CallExpression || !$this->isContextCall($node, $contents)) {
                    continue;
                }
                $method = $node->getFirstAncestor(MethodDeclaration::class);
                if (
                    $method instanceof MethodDeclaration
                    && $this->returnOnlyInjectorForwarder($method, $contents) !== null
                ) {
                    continue;
                }
                $argument = $this->contextArgument($node, $contents);
                $site = [
                    'path' => $path->value->relative,
                    'line' => substr_count($contents, "\n", 0, $node->getStartPosition()) + 1,
                ];
                if ($argument === null) {
                    $unresolved[] = $site + ['reason' => 'context_argument_missing'];
                    continue;
                }
                $this->collect($argument, $site, $contexts, $unresolved);
            }
        }
        if ($forwarders !== []) {
            $this->collectReturnOnlyForwarderCalls(
                $scannedFiles,
                $forwarders,
                $workspace,
                $parser,
                $contexts,
                $unresolved,
            );
        }
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
                'wrapperResolution' => 'static-positional-single-return-forwarders-only',
                'overrideModulesApplied' => false,
            ],
        ], [Provenance::derived()]);
    }

    /** @return array{key: string, contextParameter: int}|null */
    private function returnOnlyInjectorForwarder(MethodDeclaration $method, string $contents): ?array
    {
        $body = $method->compoundStatementOrSemicolon;
        $class = $method->getFirstAncestor(ClassDeclaration::class);
        $statements = $body instanceof CompoundStatementNode
            ? ParserNodes::elements($body->statements)
            : [];
        $className = $class instanceof ClassDeclaration ? ParserNodes::text($class->name, $contents) : null;
        $methodName = ParserNodes::text($method->name, $contents);
        $isStatic = array_filter(
            ParserNodes::elements($method->modifiers),
            fn (mixed $modifier): bool => $modifier instanceof Token
                && strtolower($modifier->getText($contents)) === 'static',
        ) !== [];
        if (
            !$body instanceof CompoundStatementNode || count($statements) !== 1
            || !$statements[0] instanceof ReturnStatement
            || !$class instanceof ClassDeclaration
            || $className === null || $className === ''
            || $methodName === null || $methodName === ''
            || !$isStatic
        ) {
            return null;
        }
        $call = $statements[0]->expression;
        if (!$call instanceof CallExpression) {
            return null;
        }
        $callee = $this->unwrap($call->callableExpression);
        if (
            !$callee instanceof ScopedPropertyAccessExpression
            || !$callee->scopeResolutionQualifier instanceof QualifiedName
            || !$this->isNamed($callee->scopeResolutionQualifier, 'BEAR\\Package\\Injector')
            || !in_array(
                strtolower(ParserNodes::text($callee->memberName, $contents) ?? ''),
                ['getinstance', 'getoverrideinstance'],
                true,
            )
        ) {
            return null;
        }
        $context = $this->contextArgument($call, $contents);
        if (!$context instanceof Variable) {
            return null;
        }
        $variable = $context->getText();
        $matchingReferences = 0;
        foreach ($call->getDescendantNodes() as $descendant) {
            if ($descendant instanceof Variable && $descendant->getText() === $variable) {
                ++$matchingReferences;
            }
        }
        if ($matchingReferences !== 1) {
            return null;
        }
        foreach (ParserNodes::elements($method->parameters) as $index => $parameter) {
            if (
                !$parameter instanceof Parameter
                || ParserNodes::text($parameter->variableName, $contents) !== $variable
                || $parameter->dotDotDotToken !== null
            ) {
                continue;
            }
            $key = strtolower(trim((string) $class->getNamespacedName(), '\\\\'))
                . '::' . strtolower($methodName);

            return ['key' => $key, 'contextParameter' => $index];
        }

        return null;
    }

    /**
     * @param list<array{0: string, 1: string}> $files
     * @param array<string, int> $forwarders
     * @param array<string, list<array{path: string, line: int}>> $contexts
     * @param list<array{path: string, line: int, reason: string}> $unresolved
     */
    private function collectReturnOnlyForwarderCalls(
        array $files,
        array $forwarders,
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
                if (!$node instanceof CallExpression) {
                    continue;
                }
                $callee = $this->unwrap($node->callableExpression);
                if (
                    !$callee instanceof ScopedPropertyAccessExpression
                    || !$callee->scopeResolutionQualifier instanceof QualifiedName
                ) {
                    continue;
                }
                $key = strtolower(trim((string) $callee->scopeResolutionQualifier->getResolvedName(), '\\\\'))
                    . '::' . strtolower(ParserNodes::text($callee->memberName, $contents) ?? '');
                if (!array_key_exists($key, $forwarders)) {
                    continue;
                }
                $argument = $this->argumentAt($node, $forwarders[$key], $contents);
                $site = [
                    'path' => $relative,
                    'line' => substr_count($contents, "\n", 0, $node->getStartPosition()) + 1,
                ];
                if ($argument === null) {
                    $unresolved[] = $site + ['reason' => 'wrapper_context_argument_unresolved'];
                    continue;
                }
                $this->collect($argument, $site, $contexts, $unresolved);
            }
        }
    }

    private function argumentAt(CallExpression $call, int $position, string $contents): ?Node
    {
        foreach (ParserNodes::elements($call->argumentExpressionList) as $i => $argument) {
            if (!$argument instanceof ArgumentExpression || $argument->dotDotDotToken !== null) {
                return null;
            }
            $name = ParserNodes::text($argument->name, $contents);
            if ($i === $position) {
                if ($name !== null) {
                    return null;
                }
                return ParserNodes::optional($argument->expression);
            }
        }

        return null;
    }

    private function isContextCall(CallExpression $call, string $contents): bool
    {
        $callee = $this->unwrap($call->callableExpression);
        if ($callee instanceof ScopedPropertyAccessExpression) {
            return $callee->scopeResolutionQualifier instanceof QualifiedName
                && $this->isNamed($callee->scopeResolutionQualifier, 'BEAR\\Package\\Injector')
                && in_array(
                    strtolower(ParserNodes::text($callee->memberName, $contents) ?? ''),
                    ['getinstance', 'getoverrideinstance'],
                    true,
                );
        }
        if ($callee instanceof ObjectCreationExpression && $callee->classTypeDesignator instanceof QualifiedName) {
            return $this->isNamed($callee->classTypeDesignator, 'BEAR\\Package\\Compiler\\Bootstrap');
        }

        return false;
    }

    private function isNamed(QualifiedName $name, string $class): bool
    {
        return strcasecmp((string) $name->getResolvedName(), $class) === 0;
    }

    private function contextArgument(CallExpression $call, string $contents): ?Node
    {
        $arguments = ParserNodes::elements($call->argumentExpressionList);
        foreach ($arguments as $argument) {
            if (!$argument instanceof ArgumentExpression || $argument->dotDotDotToken !== null) {
                return null;
            }
        }
        $named = false;
        foreach ($arguments as $argument) {
            $name = ParserNodes::text($argument->name, $contents);
            $named = $named || $name !== null;
            // Both BEAR.Package entry points take appName first and context second.
            if ($name === 'context') {
                return ParserNodes::optional($argument->expression);
            }
        }
        if ($named) {
            return null;
        }
        $argument = $arguments[1] ?? null;
        if ($argument instanceof ArgumentExpression) {
            return ParserNodes::optional($argument->expression);
        }

        return null;
    }

    private function unwrap(Node $node): Node
    {
        while ($node instanceof ParenthesizedExpression) {
            $node = $node->expression;
        }

        return $node;
    }

    /**
     * @param array{path: string, line: int} $site
     * @param array<string, list<array{path: string, line: int}>> $contexts
     * @param list<array{path: string, line: int, reason: string}> $unresolved
     */
    private function collect(Node $argument, array $site, array &$contexts, array &$unresolved): void
    {
        $argument = $this->unwrap($argument);
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
            $this->collect($trueValue, $site, $contexts, $unresolved);
            $this->collect($argument->elseExpression, $site, $contexts, $unresolved);
            return;
        }
        if (
            $argument instanceof BinaryExpression
            && $argument->operator->getText($argument->getFileContents()) === '??'
        ) {
            $this->collect($argument->leftOperand, $site, $contexts, $unresolved);
            $this->collect($argument->rightOperand, $site, $contexts, $unresolved);
            return;
        }
        $unresolved[] = $site + ['reason' => 'context_expression_unresolved'];
    }
}
