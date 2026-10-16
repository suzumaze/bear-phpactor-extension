<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Route;

use Microsoft\PhpParser\DiagnosticsProvider;
use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\DelimitedList\ArgumentExpressionList;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Parser;
use Phpactor\WorseReflection\Core\Util\NodeUtil;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\PhpStringLiteral;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceQuery;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/** Static declaration search, not an emulation of the runtime router or bootstrap rewriting. */
final class RoutePathQuery
{
    private const METHODS = ['route', 'get', 'post', 'put', 'patch', 'delete', 'head', 'options'];
    private const MAX_BYTES = 524288;
    private const MAX_CALLS = 1000;

    /** @return SemanticResult<array<string, mixed>|null> */
    public function resolve(
        WorkspaceContext $workspace,
        string $path,
        ?string $contextPath,
        ?string $httpMethod,
    ): SemanticResult {
        if (
            !str_starts_with($path, '/') || str_starts_with($path, '//')
            || preg_match('/[\x00-\x20?#]/', $path) === 1
            || ($httpMethod !== null && !in_array(strtolower($httpMethod), array_slice(self::METHODS, 1), true))
        ) {
            return SemanticResult::invalidInput();
        }
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }
        $matches = [];
        $provenance = [];
        $coverage = ['input' => 'path', 'scope' => 'static_exact_path_declarations',
            'runtimeObserved' => false, 'runtimeRewrite' => 'not_evaluated', 'provisional' => true,
            'complete' => true, 'truncated' => false, 'files' => [], 'searched' => [],
            'declarations' => 0, 'staticPaths' => 0, 'patternPaths' => 0, 'unresolved' => []];
        foreach (['aura.route.php', 'var/conf/aura.route.php'] as $relative) {
            $coverage['searched'][] = $relative;
            $result = $this->inspectFile(
                $workspace,
                $path,
                $contextPath,
                $httpMethod,
                $project->value->root() . '/' . $relative,
            );
            $scan = $result->coverage['routing'];
            if ($result->status === SemanticStatus::NotFound && $scan['files'] === []) {
                continue;
            }
            array_push($provenance, ...$result->provenance);
            array_push($coverage['files'], ...$scan['files']);
            array_push($coverage['unresolved'], ...$scan['unresolved']);
            foreach (['declarations', 'staticPaths', 'patternPaths'] as $count) {
                $coverage[$count] += $scan[$count];
            }
            $coverage['complete'] = $coverage['complete'] && $scan['complete'];
            $coverage['truncated'] = $coverage['truncated'] || $scan['truncated'];
            if ($result->value !== null) {
                $matches[] = $result->value;
            }
            array_push($matches, ...$result->candidates);
        }
        if ($coverage['files'] === []) {
            $coverage['complete'] = false;
            $coverage['unresolved'][] = ['reason' => 'no_readable_route_definition_file'];
        }
        $coverage['unresolvedCount'] = count($coverage['unresolved']);
        $coverage['unresolvedReasons'] = array_count_values(array_column($coverage['unresolved'], 'reason'));
        $coverage['unresolvedOmitted'] = count($matches) === 1 ? $coverage['unresolvedCount'] : 0;
        if (count($matches) === 1) {
            $coverage['unresolved'] = [];
        }
        $result = match (count($matches)) {
            0 => SemanticResult::failure(
                $coverage['complete'] ? SemanticStatus::NotFound : SemanticStatus::Unsupported,
                provenance: $provenance,
            ),
            1 => SemanticResult::ok($matches[0], $provenance),
            default => SemanticResult::ambiguous($matches, provenance: $provenance),
        };

        return $result->withCoverage(['routing' => $coverage]);
    }

    /** @return SemanticResult<array<string, mixed>|null> */
    private function inspectFile(
        WorkspaceContext $workspace,
        string $path,
        ?string $contextPath,
        ?string $httpMethod,
        string $filePath,
    ): SemanticResult {
        $file = $workspace->accessPolicy()->inspectExisting($filePath);
        $coverage = [
            'input' => 'path', 'scope' => 'static_exact_path_declarations',
            'runtimeObserved' => false, 'runtimeRewrite' => 'not_evaluated',
            'complete' => false, 'unresolved' => [], 'truncated' => false,
            'maxBytes' => self::MAX_BYTES, 'maxCalls' => self::MAX_CALLS,
            'files' => [], 'declarations' => 0, 'staticPaths' => 0, 'patternPaths' => 0,
        ];
        if ($file->value === null) {
            return SemanticResult::failure($file->status)->withCoverage(['routing' => $coverage]);
        }
        $coverage['files'][] = $file->value->relative;
        $source = @file_get_contents($file->value->absolute, false, null, 0, self::MAX_BYTES + 1);
        $provenance = [Provenance::savedFile($file->value->relative)];
        if ($source === false || strlen($source) > self::MAX_BYTES) {
            $coverage['unresolved'][] = ['reason' => 'unreadable_or_oversized_route_file'];
            return SemanticResult::failure(SemanticStatus::Unsupported, provenance: $provenance)
                ->withCoverage(['routing' => $coverage]);
        }
        $root = (new Parser())->parseSourceFile($source);
        if (DiagnosticsProvider::getDiagnostics($root) !== []) {
            $coverage['unresolved'][] = ['reason' => 'route_file_parse_errors'];
        }
        $matches = [];
        $calls = 0;
        foreach ($root->getDescendantNodes() as $node) {
            if (!$node instanceof CallExpression || !$node->callableExpression instanceof MemberAccessExpression) {
                continue;
            }
            if (++$calls > self::MAX_CALLS) {
                $coverage['truncated'] = true;
                break;
            }
            $name = strtolower(NodeUtil::nameFromTokenOrNode($node, $node->callableExpression->memberName));
            $line = substr_count(substr($source, 0, $node->getStartPosition()), "\n") + 1;
            if (!in_array($name, self::METHODS, true)) {
                if ($this->modifiesRoute($node)) {
                    continue; // The underlying declaration carries one combined explanation.
                }
                $coverage['unresolved'][] = ['line' => $line, 'reason' => 'route_configuration_not_evaluated'];
                continue;
            }
            ++$coverage['declarations'];
            $args = $this->arguments($node, $source);
            $route = $args['name'] ?? $args[0] ?? null;
            $pattern = $args['path'] ?? $args[1] ?? null;
            $handler = array_key_exists('handler', $args) ? $args['handler']
                : (array_key_exists(2, $args) ? $args[2] : $route);
            if ($pattern !== null) {
                ++$coverage[strpbrk($pattern, '{}') === false ? 'staticPaths' : 'patternPaths'];
            }
            $reason = match (true) {
                $route === null || $pattern === null || $handler === null => 'dynamic_arguments',
                strpbrk($pattern, '{}') !== false => 'path_pattern_not_evaluated',
                $node->parent instanceof MemberAccessExpression => 'modified_by_chain',
                !$this->plainDeclaration($node) => 'conditional_or_nested_declaration',
                !str_starts_with($route, '/') || !str_starts_with($handler, '/') => 'unsupported_resource_handler',
                default => null,
            };
            if ($reason !== null) {
                $coverage['unresolved'][] = [
                    'file' => $file->value->relative, 'line' => $line,
                    'route' => $route, 'path' => $pattern, 'reason' => $reason,
                ];
                continue;
            }
            if (
                $pattern !== $path
                || ($httpMethod !== null && $name !== 'route' && $name !== strtolower($httpMethod))
            ) {
                continue;
            }
            $resource = (new ResourceQuery())->resolveInWorkspace($workspace, 'page://self' . $handler, $contextPath);
            if ($resource->value === null) {
                $coverage['unresolved'][] = [
                    'line' => $line, 'route' => $route, 'reason' => 'resource_' . $resource->status->value,
                ];
                continue;
            }
            $resolved = $resource->value;
            $relative = $workspace->accessPolicy()->inspectExisting($resolved->file)->value;
            if ($relative === null) {
                continue;
            }
            array_push($provenance, ...$resource->provenance);
            $matches[] = [
                'input' => 'path', 'path' => $path, 'httpMethod' => $httpMethod,
                'route' => $route, 'handler' => $handler,
                'declaredMethods' => $name === 'route' ? [] : [strtoupper($name)],
                'routeUri' => 'page://self' . $route, 'canonicalUri' => $resolved->canonicalUri(),
                'resource' => [
                    'uri' => $resolved->uri->uri(), 'canonicalUri' => $resolved->canonicalUri(),
                    'resolvedVia' => $resolved->resolvedVia, 'fqn' => $resolved->fqn, 'path' => $relative->relative,
                ],
                'declaration' => ['path' => $file->value->relative, 'line' => $line],
            ];
        }
        $coverage['complete'] = !$coverage['truncated'] && $coverage['unresolved'] === [];
        $coverage['provisional'] = true; // Context activation, precedence and runtime rewriting are not evaluated.
        $result = match (count($matches)) {
            0 => SemanticResult::failure(
                $coverage['complete'] ? SemanticStatus::NotFound : SemanticStatus::Unsupported,
                provenance: $provenance,
            ),
            1 => SemanticResult::ok($matches[0], $provenance),
            default => SemanticResult::ambiguous($matches, provenance: $provenance),
        };

        return $result->withCoverage(['routing' => $coverage]);
    }

    /** @return array<int|string, string|null> */
    private function arguments(CallExpression $call, string $source): array
    {
        $args = [];
        $list = $call->argumentExpressionList;
        if (!$list instanceof ArgumentExpressionList) {
            return $args;
        }
        foreach ($list->getElements() as $argument) {
            if (!$argument instanceof ArgumentExpression) {
                continue;
            }
            $literal = $argument->expression;
            $value = null;
            if ($argument->dotDotDotToken === null && $literal instanceof StringLiteral) {
                $dynamic = is_array($literal->children)
                    && array_filter($literal->children, static fn ($child): bool => $child instanceof Node) !== [];
                if (!$dynamic && in_array(substr($literal->getText(), 0, 1), ["'", '"'], true)) {
                    $value = PhpStringLiteral::decode($literal);
                }
            }
            $key = $argument->name === null ? count($args) : $argument->name->getText($source);
            $args[$key] = $value;
        }

        return $args;
    }

    private function modifiesRoute(CallExpression $node): bool
    {
        while ($node->callableExpression instanceof MemberAccessExpression) {
            $receiver = $node->callableExpression->dereferencableExpression;
            if (
                !$receiver instanceof CallExpression
                || !$receiver->callableExpression instanceof MemberAccessExpression
            ) {
                return false;
            }
            $name = strtolower(NodeUtil::nameFromTokenOrNode($receiver, $receiver->callableExpression->memberName));
            if (in_array($name, self::METHODS, true)) {
                return true;
            }
            $node = $receiver;
        }

        return false;
    }

    private function plainDeclaration(CallExpression $node): bool
    {
        for ($parent = $node->parent; $parent !== null; $parent = $parent->parent) {
            $type = substr(strrchr($parent::class, '\\') ?: '', 1);
            if (
                !in_array($type, [
                'ExpressionStatement', 'CompoundStatementNode', 'AnonymousFunctionCreationExpression',
                'ReturnStatement', 'SourceFileNode',
                ], true)
            ) {
                return false;
            }
        }

        return true;
    }
}
