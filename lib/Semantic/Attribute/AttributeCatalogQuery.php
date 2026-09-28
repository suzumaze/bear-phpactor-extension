<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Attribute;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\Expression\BinaryExpression;
use Microsoft\PhpParser\Node\Expression\ScopedPropertyAccessExpression;
use Microsoft\PhpParser\Node\NumericLiteral;
use Microsoft\PhpParser\Node\Parameter;
use Microsoft\PhpParser\Node\QualifiedName;
use Suzumaze\BearPhpactor\Semantic\Aop\SourceMatcher;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\BearPackageComposition;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSource;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSourceIndex;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ModuleInterpreter;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ParserNodes;
use Suzumaze\BearPhpactor\Semantic\Di\DiContainerQuery;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectReportPage;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Util\PhpAttributeName;

/** Available PHP attributes; documentation is quoted from source, never generated. */
final readonly class AttributeCatalogQuery
{
    /** @return SemanticResult<array<string, mixed>|null> */
    public function listInWorkspace(
        WorkspaceContext $workspace,
        ?string $applicationContext = null,
        ?string $attribute = null,
        ?string $contextPath = null,
        int $limit = 50,
        int $offset = 0,
    ): SemanticResult {
        if (
            ($applicationContext !== null
            && preg_match('/^[A-Za-z0-9_]+(?:-[A-Za-z0-9_]+)*$/', $applicationContext) !== 1)
            || $limit < 1 || $limit > 100 || $offset < 0
        ) {
            return SemanticResult::invalidInput();
        }
        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }
        $root = $project->value->root();
        $classes = new ClassSourceIndex($root, $workspace->root());
        $interpreter = new ModuleInterpreter($classes);
        $pointcuts = [];
        if ($applicationContext !== null) {
            $name = DiContainerQuery::appName($project->value->psr4(), $root);
            if ($name === null) {
                return SemanticResult::unsupported();
            }
            $pointcuts = (new BearPackageComposition($classes, $interpreter))($name, $applicationContext)->pointcuts;
        }
        $scanMode = 'bounded_composer_scan';
        $scan = null;
        $targetedLookup = null;
        if ($attribute !== null) {
            $targetedLookup = $classes->findComposerMapped($attribute);
            $mapped = $targetedLookup['source'];
            if (
                !$targetedLookup['truncated']
                && $mapped !== null
                && $mapped->isClass()
                && $this->attributeDeclaration($mapped) !== null
            ) {
                $scanMode = 'targeted_composer_definition';
                $scan = [
                    'classes' => [$mapped],
                    'scannedFiles' => $targetedLookup['scannedFiles'],
                    'skippedFiles' => $targetedLookup['skippedFiles'],
                    'truncated' => false,
                ];
            }
        }
        $scan ??= $classes->attributeSources();
        if ($scanMode === 'bounded_composer_scan' && $targetedLookup !== null) {
            $scan['scannedFiles'] += $targetedLookup['scannedFiles'];
            $scan['skippedFiles'] += $targetedLookup['skippedFiles'];
            $scan['truncated'] = $scan['truncated'] || $targetedLookup['truncated'];
        }
        $items = [];
        foreach ($scan['classes'] as $source) {
            if (
                !$source->isClass()
                || ($attribute !== null && strcasecmp($source->name, ltrim($attribute, '\\')) !== 0)
            ) {
                continue;
            }
            $declaration = $this->attributeDeclaration($source);
            if ($declaration === null) {
                continue;
            }
            $flags = 63;
            $arguments = ParserNodes::elements($declaration->argumentExpressionList);
            if ($arguments !== []) {
                $arg = count($arguments) === 1 ? $arguments[0] : null;
                $flags = $arg instanceof ArgumentExpression
                    && ($arg->name === null || ParserNodes::text($arg->name, $arg->getFileContents()) === 'flags')
                    && $arg->expression instanceof Node ? $this->flags($arg->expression) : null;
            }
            $targets = [];
            $targetFlags = ['class' => 1, 'function' => 2, 'method' => 4,
                'property' => 8, 'class_constant' => 16, 'parameter' => 32];
            foreach ($targetFlags as $target => $bit) {
                if ($flags !== null && ($flags & $bit) !== 0) {
                    $targets[] = $target;
                }
            }
            $parameters = [];
            $constructor = $interpreter->findMethodIn($source->name, '__construct');
            foreach (ParserNodes::elements($constructor[1]->parameters ?? null) as $parameter) {
                if ($parameter instanceof Parameter) {
                    $parameters[] = [
                        'name' => $parameter->getName(),
                        'type' => ($parameter->questionToken === null ? '' : '?')
                            . ($parameter->typeDeclarationList?->getText() ?? ''),
                        'hasDefault' => $parameter->default !== null,
                        'variadic' => $parameter->dotDotDotToken !== null,
                    ];
                }
            }
            $mechanisms = [];
            foreach ($pointcuts as $pointcut) {
                if (
                    !($pointcut->classMatcher?->references($source->name) ?? false)
                    && !($pointcut->methodMatcher?->references($source->name) ?? false)
                ) {
                    continue;
                }
                $interceptors = [];
                foreach ($pointcut->interceptors as $interceptor) {
                    $invoke = $interpreter->findMethodIn($interceptor, 'invoke');
                    $interceptors[] = [
                        'class' => $interceptor,
                        'invoke' => $invoke === null ? null : [
                            'path' => $invoke[0]->path,
                            'line' => 1 + substr_count(
                                substr($invoke[0]->contents, 0, $invoke[1]->getStartPosition()),
                                "\n",
                            ),
                        ],
                    ];
                }
                $mechanisms[] = [
                    'kind' => 'aop_condition_reference',
                    'interceptors' => $interceptors,
                    'classMatcher' => $pointcut->classMatcher?->toArray(),
                    'methodMatcher' => $pointcut->methodMatcher?->toArray(),
                    'origin' => $pointcut->origin->toArray(),
                ];
            }
            if (in_array('Ray\Di\Di\Qualifier', SourceMatcher::attributes($source->node), true)) {
                $mechanisms[] = ['kind' => 'di_qualifier_marker'];
            }
            $doc = $source->node->getDocCommentText() ?? '';
            $items[] = [
                'attribute' => $source->name,
                'origin' => $this->origin($classes->absolutePath($source->path), $root),
                'path' => $source->path,
                'line' => 1 + substr_count(substr($source->contents, 0, $source->node->getStartPosition()), "\n"),
                'targets' => $flags === null ? null : $targets,
                'repeatable' => $flags === null ? null : ($flags & 64) !== 0,
                'constructorParameters' => $parameters,
                'docblock' => substr($doc, 0, 4000),
                'docblockTruncated' => strlen($doc) > 4000,
                'mechanisms' => array_slice($mechanisms, 0, 30),
                'mechanismTotal' => count($mechanisms),
                'mechanismsTruncated' => count($mechanisms) > 30,
                'consumerStatus' => $mechanisms === [] ? 'unknown' : 'source_references_found',
            ];
        }
        $page = ProjectReportPage::slice($items, $offset, $limit, ProjectReportPage::serializedBytes(...));

        return SemanticResult::ok([
            'applicationContext' => $applicationContext,
            'items' => $page,
            'total' => count($items),
            'offset' => $offset,
            'truncated' => $offset + count($page) < count($items),
            'scannedFiles' => $scan['scannedFiles'],
            'skippedFiles' => $scan['skippedFiles'],
            'scanTruncated' => $scan['truncated'],
            'unknowns' => array_slice($interpreter->unknowns, 0, 100),
            'unknownTotal' => count($interpreter->unknowns),
            'coverage' => [
                'basis' => 'saved_source',
                'discovery' => 'composer_exact_definition_with_bounded_scan_fallback',
                'scanMode' => $scanMode,
                'contextEvaluated' => $applicationContext !== null,
                'aopReferencesProveApplication' => false,
                'frameworkConsumersResolved' => false,
                'usageCountsResolved' => false,
                'defaultValuesReturned' => false,
                'applicationCodeExecuted' => false,
            ],
        ], [Provenance::derived()]);
    }

    private function attributeDeclaration(ClassSource $source): ?Attribute
    {
        foreach (ParserNodes::elements($source->node->attributes ?? null) as $group) {
            foreach (ParserNodes::elements($group->attributes ?? null) as $attribute) {
                if (
                    $attribute instanceof Attribute
                    && strcasecmp(PhpAttributeName::resolve($attribute) ?? '', 'Attribute') === 0
                ) {
                    return $attribute;
                }
            }
        }

        return null;
    }

    private function flags(Node $node): ?int
    {
        if ($node instanceof NumericLiteral) {
            $value = filter_var($node->getText(), FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_HEX | FILTER_FLAG_ALLOW_OCTAL);

            return $value === false || $value < 0 || ($value & ~127) !== 0 ? null : $value;
        }
        if ($node instanceof BinaryExpression && $node->operator->getText($node->getFileContents()) === '|') {
            $left = $this->flags($node->leftOperand);
            $right = $this->flags($node->rightOperand);

            $value = $left === null || $right === null ? null : $left | $right;

            return $value === null || $value < 0 || ($value & ~127) !== 0 ? null : $value;
        }
        if (
            $node instanceof ScopedPropertyAccessExpression && $node->scopeResolutionQualifier instanceof QualifiedName
            && strcasecmp((string) $node->scopeResolutionQualifier->getResolvedName(), 'Attribute') === 0
        ) {
            return match (ParserNodes::text($node->memberName, $node->getFileContents())) {
                'TARGET_ALL' => 63, 'TARGET_CLASS' => 1, 'TARGET_FUNCTION' => 2, 'TARGET_METHOD' => 4,
                'TARGET_PROPERTY' => 8, 'TARGET_CLASS_CONSTANT' => 16, 'TARGET_PARAMETER' => 32, 'IS_REPEATABLE' => 64,
                default => null,
            };
        }

        return null;
    }

    /** @return array{kind: string, package: ?string} */
    private function origin(string $file, string $root): array
    {
        $json = @file_get_contents($root . '/vendor/composer/installed.json');
        $data = $json === false ? [] : json_decode($json, true);
        foreach (is_array($data) ? ($data['packages'] ?? $data) : [] as $package) {
            if (!is_array($package) || !is_string($package['install-path'] ?? null)) {
                continue;
            }
            $dir = realpath($root . '/vendor/composer/' . $package['install-path']);
            if ($dir !== false && str_starts_with($file, $dir . '/')) {
                return [
                    'kind' => 'package',
                    'package' => is_string($package['name'] ?? null) ? $package['name'] : null,
                ];
            }
        }

        return ['kind' => 'application', 'package' => null];
    }
}
