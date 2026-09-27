<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Aop;

use Microsoft\PhpParser\Node\MethodDeclaration;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ParserNodes;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\BearPackageComposition;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSource;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSourceIndex;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ModuleInterpreter;
use Suzumaze\BearPhpactor\Semantic\Di\DiContainerQuery;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectReportPage;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceInventoryQuery;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/** Source matching under the Ray.Aop PHP-attribute ordering model, without weaving or executing PHP. */
final readonly class AopApplicationsQuery
{
    /** @return SemanticResult<array<string, mixed>|null> */
    public function listInWorkspace(
        WorkspaceContext $workspace,
        string $applicationContext,
        ?string $uri = null,
        ?string $interceptor = null,
        ?string $attribute = null,
        ?string $methodFilter = null,
        ?string $contextPath = null,
        int $limit = 50,
        int $offset = 0,
    ): SemanticResult {
        if (
            preg_match('/^[A-Za-z0-9_]+(?:-[A-Za-z0-9_]+)*$/', $applicationContext) !== 1
            || ($methodFilter !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,254}$/', $methodFilter) !== 1)
            || $limit < 1 || $limit > 100 || $offset < 0
        ) {
            return SemanticResult::invalidInput();
        }
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }
        $root = $project->value->root();
        $appName = DiContainerQuery::appName($project->value->psr4(), $root);
        if ($appName === null) {
            return SemanticResult::unsupported();
        }
        $classes = new ClassSourceIndex($root, $workspace->root());
        $interpreter = new ModuleInterpreter($classes);
        $container = (new BearPackageComposition($classes, $interpreter))($appName, $applicationContext);
        $inventory = (new ResourceInventoryQuery())->allInWorkspace($workspace, contextPath: $contextPath);
        if ($inventory->value === null) {
            return SemanticResult::failure($inventory->status);
        }
        $matcher = new SourceMatcher($classes);
        $items = [];
        $unresolved = [];
        $applicationUnknowns = [];
        $unresolvedApplicationTotal = 0;
        $scanned = 0;
        $unresolvedPointcutTotal = count(array_filter($container->pointcuts, static fn (ComposedPointcut $p): bool =>
            $p->classMatcher === null || $p->methodMatcher === null || !$p->interceptorsKnown));
        foreach ($inventory->value->resources as $resource) {
            if ($uri !== null && $resource->uri->uri() !== $uri) {
                continue;
            }
            if (++$scanned > 2000) {
                $unresolved[] = ['reason' => 'resource_scan_limit'];
                break;
            }
            $binding = $container->bindings[$resource->fqn . '-'] ?? null;
            $class = $binding === null ? $resource->fqn : $binding['target'];
            if ($binding !== null && $binding['kind'] !== 'dependency') {
                $unresolved[] = ['uri' => $resource->uri->uri(), 'reason' => 'resource_binding_not_class'];
                continue;
            }
            $source = $class === null ? null : $classes->find($class);
            if ($source === null) {
                $unresolved[] = ['uri' => $resource->uri->uri(), 'reason' => 'resource_source_missing'];
                continue;
            }
            $methods = $matcher->methods($source);
            if (!$methods['complete']) {
                $unresolved[] = ['uri' => $resource->uri->uri(), 'reason' => 'inherited_or_trait_methods_unresolved'];
            }
            foreach ($methods['methods'] as [$declaring, $method]) {
                if (
                    $methodFilter === null
                    ? !str_starts_with($method->getName(), 'on')
                    : $method->getName() !== $methodFilter
                ) {
                    continue;
                }
                $application = $this->application($container->pointcuts, $matcher, $source, $method);
                foreach ($application['unresolvedPointcuts'] as $unresolvedPointcut) {
                    ++$unresolvedApplicationTotal;
                    if (count($applicationUnknowns) < 100) {
                        $applicationUnknowns[] = [
                            ...$unresolvedPointcut,
                            'uri' => $resource->uri->uri(),
                            'resource' => $resource->fqn,
                            'method' => $method->getName(),
                        ];
                    }
                }
                if ($application['chain'] === [] && $application['unresolvedPointcuts'] === []) {
                    continue;
                }
                if (
                    $interceptor !== null
                    && !in_array(ltrim($interceptor, '\\'), array_column($application['chain'], 'interceptor'), true)
                ) {
                    continue;
                }
                if (
                    $attribute !== null && !array_filter($application['chain'], static fn (array $entry): bool =>
                    in_array(ltrim($attribute, '\\'), $entry['referencedAttributes'], true))
                ) {
                    continue;
                }
                $modifiers = array_map(
                    static fn ($token): string => strtolower($token->getText($declaring->contents)),
                    ParserNodes::elements($method->modifiers)
                );
                $blockers = [];
                if ($source->isFinal()) {
                    $blockers[] = 'final_class';
                }
                if (in_array('final', $modifiers, true)) {
                    $blockers[] = 'final_method';
                }
                $items[] = [
                    'uri'  => $resource->uri->uri(),
                    'resource' => $resource->fqn,
                    'target' => $source->name,
                    'method' => $method->getName(),
                    'path' => $declaring->path,
                    'line' => 1 + substr_count(substr($declaring->contents, 0, $method->getStartPosition()), "\n"),
                    'attributes' => SourceMatcher::attributes($method),
                    'weavingBlockers' => $blockers,
                    'status' => $interpreter->unknowns === [] && $unresolvedPointcutTotal === 0
                        && $application['unresolvedPointcuts'] === []
                        && $methods['complete'] ? 'source_matched' : 'provisional',
                    'chain' => array_slice($application['chain'], 0, 100),
                    'chainTotal' => count($application['chain']),
                    'chainTruncated' => count($application['chain']) > 100,
                    'unresolvedPointcuts' => array_slice($application['unresolvedPointcuts'], 0, 50),
                    'unresolvedPointcutTotal' => count($application['unresolvedPointcuts']),
                ];
            }
        }
        $page = ProjectReportPage::slice($items, $offset, $limit, ProjectReportPage::serializedBytes(...));

        return SemanticResult::ok([
            'applicationContext' => $applicationContext,
            'items' => $page,
            'total' => count($items),
            'offset' => $offset,
            'truncated' => $offset + count($page) < count($items),
            'unknowns' => array_slice([...$interpreter->unknowns, ...$unresolved, ...$applicationUnknowns], 0, 100),
            'unknownTotal' => count($interpreter->unknowns) + count($unresolved) + $unresolvedApplicationTotal,
            'unresolvedPointcutTotal' => $unresolvedPointcutTotal + $unresolvedApplicationTotal,
            'coverage' => [
                'basis' => 'source_matching_model',
                'orderingModel' => 'ray_aop_php_attribute_onion',
                'runtimeObserved' => false,
                'weavingValidated' => false,
                'legacyDocblockAnnotationsResolved' => false,
                'traitMethodsResolved' => false,
                'instanceValuesReturned' => false,
                'methodScope' => $methodFilter === null ? 'default_public_on_methods' : 'exact_public_method',
            ],
        ], [Provenance::derived()]);
    }

    /**
     * @param list<ComposedPointcut> $pointcuts
     * @return array{chain: list<array<string, mixed>>, unresolvedPointcuts: list<array<string, mixed>>}
     */
    private function application(
        array $pointcuts,
        SourceMatcher $matcher,
        ClassSource $class,
        MethodDeclaration $method,
    ): array {
        // Bind::getAnnotationPointcuts replaces direct annotated matchers before class matching.
        $remaining = [];
        foreach ($pointcuts as $i => $pointcut) {
            $key = $pointcut->methodMatcher?->kind === 'annotatedwith' ? $pointcut->methodMatcher->value : $i;
            $remaining[$key ?? $i] = $pointcut;
        }
        $ordered = [];
        $orderingUnknown = false;
        foreach ($remaining as $key => $pointcut) {
            if ($pointcut->priority) {
                $ordered[] = $pointcut;
                unset($remaining[$key]);
            }
        }
        foreach (SourceMatcher::attributes($method) as $attribute) {
            foreach ($remaining as $key => $pointcut) {
                if (
                    $pointcut->methodMatcher?->kind === 'annotatedwith'
                    && $matcher->isA($attribute, (string) $pointcut->methodMatcher->value) === null
                ) {
                    $orderingUnknown = true;
                }
                if (
                    $pointcut->methodMatcher?->kind === 'annotatedwith'
                    && $matcher->isA($attribute, (string) $pointcut->methodMatcher->value) === true
                ) {
                    $ordered[] = $pointcut;
                    unset($remaining[$key]);
                }
            }
        }
        array_push($ordered, ...array_values($remaining));
        $chain = [];
        $unknown = $orderingUnknown ? [['reason' => 'attribute_inheritance_order_unresolved']] : [];
        foreach ($ordered as $pointcut) {
            $classMatch = $matcher->matches($pointcut->classMatcher, $class);
            $methodMatch = $matcher->matches($pointcut->methodMatcher, $class, $method);
            if ($classMatch === false || $methodMatch === false) {
                continue;
            }
            if ($classMatch === null || $methodMatch === null || !$pointcut->interceptorsKnown) {
                $unknown[] = [
                    'reason' => 'matcher_or_interceptors_unresolved',
                    'origin' => $pointcut->origin->toArray(),
                ];
                continue;
            }
            foreach ($pointcut->interceptors as $interceptor) {
                $chain[] = [
                    'interceptor' => $interceptor,
                    'priority' => $pointcut->priority,
                    'referencedAttributes' => $this->attributeReferences($pointcut),
                    'classMatcher' => $pointcut->classMatcher?->toArray(),
                    'methodMatcher' => $pointcut->methodMatcher?->toArray(),
                    'origin' => $pointcut->origin->toArray(),
                ];
            }
        }

        return ['chain' => $chain, 'unresolvedPointcuts' => $unknown];
    }
    /** @return list<string> */
    private function attributeReferences(ComposedPointcut $pointcut): array
    {
        return array_values(array_unique([
            ...($pointcut->classMatcher?->attributeReferences() ?? []),
            ...($pointcut->methodMatcher?->attributeReferences() ?? []),
        ]));
    }
}
