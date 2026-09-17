<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\DelimitedList;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\MethodDeclaration;
use Microsoft\PhpParser\Node\NumericLiteral;
use Microsoft\PhpParser\Node\Parameter;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Parser;
use Microsoft\PhpParser\Token;
use Microsoft\PhpParser\TokenKind;
use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Util\PhpClassDeclaration;

/**
 * Reads statically knowable facts from one resolved Resource class.
 */
final class ResourceFactsQuery
{
    private const MAX_PHP_BYTES = 2097152;
    private const MAX_ARGUMENT_LENGTH = 2048;
    private const MAX_CACHE_ENTRIES = 128;
    /** @var array<string,string> */
    private const SUPPORTED_ATTRIBUTES = [
        'BEAR\ApiDoc\Annotation\Alps' => 'Alps',
        'BEAR\RepositoryModule\Annotation\Cacheable' => 'Cacheable',
        'BEAR\RepositoryModule\Annotation\CacheableResponse' => 'CacheableResponse',
        'BEAR\RepositoryModule\Annotation\DonutCache' => 'DonutCache',
        'BEAR\RepositoryModule\Annotation\HttpCache' => 'HttpCache',
        'BEAR\RepositoryModule\Annotation\Purge' => 'Purge',
        'BEAR\RepositoryModule\Annotation\Refresh' => 'Refresh',
        'BEAR\Resource\Annotation\Embed' => 'Embed',
        'BEAR\Resource\Annotation\JsonSchema' => 'JsonSchema',
        'BEAR\Resource\Annotation\Link' => 'Link',
    ];
    private const EMBED_FQN = 'BEAR\Resource\Annotation\Embed';
    private const LINK_FQN = 'BEAR\Resource\Annotation\Link';

    /**
     * @var array<string,array{fingerprint:string,result:SemanticResult<ResourceFacts|null>}>
     */
    private array $cache = [];

    /** @var list<string> Least recently used to most recently used. */
    private array $cacheOrder = [];

    public function __construct(
        private ResourceQuery $resourceQuery = new ResourceQuery(),
        private Parser $parser = new Parser(),
        private int $maxCacheEntries = self::MAX_CACHE_ENTRIES,
    ) {
    }

    /** @return SemanticResult<ResourceFacts|null> */
    public function describeInWorkspace(
        WorkspaceContext $workspace,
        string $uri,
        ?string $contextPath = null,
    ): SemanticResult {
        $resolved = $this->resourceQuery->resolveInWorkspace($workspace, $uri, $contextPath);
        if ($resolved->status === SemanticStatus::Ok && $resolved->value !== null) {
            return $this->facts($workspace, $resolved->value);
        }
        if ($resolved->status !== SemanticStatus::Ambiguous) {
            return SemanticResult::failure($resolved->status);
        }

        $candidates = [];
        foreach ($resolved->candidates as $candidate) {
            $facts = $this->facts($workspace, $candidate);
            if ($facts->value === null) {
                return SemanticResult::failure($facts->status);
            }
            $candidates[] = $facts->value;
        }

        return SemanticResult::ambiguous($candidates);
    }

    /**
     * Parse facts for a Resource that has already been resolved by another
     * semantic query. This avoids resolving duplicate URI identities again
     * while building workspace-wide relationship indexes.
     *
     * @return SemanticResult<ResourceFacts|null>
     */
    public function describeResolutionInWorkspace(
        WorkspaceContext $workspace,
        ResourceResolution $resource,
    ): SemanticResult {
        return $this->facts($workspace, $resource);
    }

    /** @return SemanticResult<ResourceFacts|null> */
    private function facts(WorkspaceContext $workspace, ResourceResolution $resource): SemanticResult
    {
        $path = $workspace->accessPolicy()->inspectExisting($resource->file);
        if ($path->value === null) {
            return SemanticResult::failure($path->status);
        }

        $source = @file_get_contents($path->value->absolute, false, null, 0, self::MAX_PHP_BYTES + 1);
        if ($source === false) {
            return SemanticResult::notFound();
        }
        if (strlen($source) > self::MAX_PHP_BYTES) {
            return SemanticResult::parseError();
        }

        clearstatcache(true, $path->value->absolute);
        $modified = filemtime($path->value->absolute);
        $fingerprint = hash('sha256', $source) . ':' . ($modified === false ? 'unknown' : (string) $modified)
            . ':' . strlen($source);
        $cacheKey = $workspace->root() . "\0" . $path->value->absolute . "\0"
            . $resource->uri->uri() . "\0" . $resource->fqn;
        if (isset($this->cache[$cacheKey]) && $this->cache[$cacheKey]['fingerprint'] === $fingerprint) {
            $this->touchCache($cacheKey);

            return $this->cache[$cacheKey]['result'];
        }

        $class = PhpClassDeclaration::findInSource($source, $path->value->absolute, $this->parser);
        if (!$class instanceof ClassDeclaration) {
            $result = SemanticResult::parseError();
            $this->cacheResult($cacheKey, $fingerprint, $result);

            return $result;
        }

        $methods = [];
        $relations = [];
        $attributes = $this->attributeFacts($class, 'class', null, $source);
        foreach ($class->classMembers->getChildNodes() as $member) {
            if (!$member instanceof MethodDeclaration || !$this->isResourceMethod($member)) {
                continue;
            }

            $method = new ResourceMethodFact($member->getName(), $this->parameters($member));
            $methods[] = $method;
            array_push(
                $attributes,
                ...$this->attributeFacts($member, 'method', $method->name, $source),
            );
            array_push(
                $relations,
                ...$this->relations($member, $method->name, $resource, $source),
            );
        }

        usort(
            $methods,
            static fn (ResourceMethodFact $left, ResourceMethodFact $right): int => $left->name <=> $right->name,
        );
        usort(
            $relations,
            static fn (ResourceRelationFact $left, ResourceRelationFact $right): int => [
                $left->sourceUri->uri(),
                $left->targetUri->uri(),
                $left->rel,
                $left->kind,
                $left->sourceFile,
                $left->byteOffset,
            ] <=> [
                $right->sourceUri->uri(),
                $right->targetUri->uri(),
                $right->rel,
                $right->kind,
                $right->sourceFile,
                $right->byteOffset,
            ],
        );
        usort(
            $attributes,
            static fn (ResourceAttributeFact $left, ResourceAttributeFact $right): int => [
                $left->target,
                $left->methodName ?? '',
                $left->fqn,
                $left->byteStart,
            ] <=> [
                $right->target,
                $right->methodName ?? '',
                $right->fqn,
                $right->byteStart,
            ],
        );

        $result = SemanticResult::ok(
            new ResourceFacts($resource, $methods, $relations, $attributes),
            [Provenance::savedFile($path->value->relative)],
        );
        $this->cacheResult($cacheKey, $fingerprint, $result);

        return $result;
    }

    /** @param SemanticResult<ResourceFacts|null> $result */
    private function cacheResult(string $key, string $fingerprint, SemanticResult $result): void
    {
        if ($this->maxCacheEntries < 1) {
            return;
        }
        $this->cache[$key] = ['fingerprint' => $fingerprint, 'result' => $result];
        $this->touchCache($key);
        while (count($this->cacheOrder) > $this->maxCacheEntries) {
            $oldest = array_shift($this->cacheOrder);
            if ($oldest !== null) {
                unset($this->cache[$oldest]);
            }
        }
    }

    private function touchCache(string $key): void
    {
        $position = array_search($key, $this->cacheOrder, true);
        if ($position !== false) {
            unset($this->cacheOrder[$position]);
            $this->cacheOrder = array_values($this->cacheOrder);
        }
        $this->cacheOrder[] = $key;
    }

    private function isResourceMethod(MethodDeclaration $method): bool
    {
        $name = $method->getName();
        if (
            strlen($name) < 3
            || !str_starts_with($name, 'on')
            || $name[2] < 'A'
            || $name[2] > 'Z'
        ) {
            return false;
        }

        return !$method->hasModifier(TokenKind::PrivateKeyword)
            && !$method->hasModifier(TokenKind::ProtectedKeyword);
    }

    /** @return list<ResourceParameterFact> */
    private function parameters(MethodDeclaration $method): array
    {
        return $this->parametersFromList($method->parameters);
    }

    /** @return list<ResourceParameterFact> */
    private function parametersFromList(mixed $parameterList): array
    {
        if (!$parameterList instanceof DelimitedList) {
            return [];
        }

        $parameters = [];
        foreach ($parameterList->getElements() as $parameter) {
            if (!$parameter instanceof Parameter) {
                continue;
            }
            $name = $parameter->getName();
            if (!is_string($name) || $name === '') {
                continue;
            }

            $type = $parameter->typeDeclarationList instanceof Node
                ? trim($parameter->typeDeclarationList->getText())
                : '';
            if ($parameter->questionToken instanceof Token) {
                $type = '?' . $type;
            }
            $parameters[] = new ResourceParameterFact($name, $type === '' ? null : $type);
        }

        return $parameters;
    }

    /** @return list<ResourceRelationFact> */
    private function relations(
        MethodDeclaration $method,
        string $methodName,
        ResourceResolution $resource,
        string $source,
    ): array {
        $relations = [];
        foreach ($this->attributes($method) as $attribute) {
            $kind = $this->relationKind($attribute);
            if ($kind === null) {
                continue;
            }

            $targetArgument = $kind === 'embed' ? 'src' : 'href';
            $target = $this->stringArgument($attribute, $targetArgument, 2, $source);
            if ($target === null) {
                continue;
            }
            $targetUri = $this->targetUri($target, $resource->uri);
            if ($targetUri === null) {
                continue;
            }

            $targetMethod = 'onGet';
            if ($kind === 'link') {
                $linkMethod = $this->stringArgument($attribute, 'method', 3, $source);
                if ($this->hasArgument($attribute, 'method', 3, $source) && $linkMethod === null) {
                    $targetMethod = null;
                } elseif ($linkMethod !== null && $linkMethod !== '') {
                    $targetMethod = 'on' . ucfirst(strtolower($linkMethod));
                }
            }

            $relations[] = new ResourceRelationFact(
                $kind,
                $this->stringArgument($attribute, 'rel', 1, $source) ?? '',
                $resource->uri,
                $methodName,
                $targetUri,
                $targetMethod,
                $resource->file,
                $attribute->getStartPosition(),
            );
        }

        return $relations;
    }

    /** @return iterable<Attribute> */
    private function attributes(ClassDeclaration|MethodDeclaration $declaration): iterable
    {
        foreach (is_array($declaration->attributes) ? $declaration->attributes : [] as $group) {
            foreach ($group->getChildNodes() as $list) {
                if (!$list instanceof DelimitedList) {
                    continue;
                }
                foreach ($list->getElements() as $attribute) {
                    if ($attribute instanceof Attribute) {
                        yield $attribute;
                    }
                }
            }
        }
    }

    /**
     * @param 'class'|'method' $target
     * @return list<ResourceAttributeFact>
     */
    private function attributeFacts(
        ClassDeclaration|MethodDeclaration $declaration,
        string $target,
        ?string $methodName,
        string $source,
    ): array {
        $facts = [];
        foreach ($this->attributes($declaration) as $attribute) {
            $fqn = $this->attributeFqn($attribute);
            if ($fqn === null || !isset(self::SUPPORTED_ATTRIBUTES[$fqn])) {
                continue;
            }

            $arguments = [];
            if ($attribute->argumentExpressionList instanceof DelimitedList) {
                foreach ($attribute->argumentExpressionList->getElements() as $argument) {
                    if ($argument instanceof ArgumentExpression) {
                        $arguments[] = $this->attributeArgument($argument, $source);
                    }
                }
            }
            $facts[] = new ResourceAttributeFact(
                $target,
                $methodName,
                self::SUPPORTED_ATTRIBUTES[$fqn],
                $fqn,
                $arguments,
                $attribute->getStartPosition(),
                $attribute->getEndPosition(),
            );
        }

        return $facts;
    }

    private function attributeFqn(Attribute $attribute): ?string
    {
        if (!$attribute->name instanceof QualifiedName) {
            return null;
        }

        $resolved = $attribute->name->getResolvedName();

        return $resolved === null ? null : ltrim((string) $resolved, '\\');
    }

    private function attributeArgument(
        ArgumentExpression $argument,
        string $source,
    ): ResourceAttributeArgumentFact {
        $name = $argument->name instanceof Token ? $argument->name->getText($source) : null;
        if (!$argument->expression instanceof Node) {
            return new ResourceAttributeArgumentFact($name, 'dynamic', null);
        }
        if ($argument->expression instanceof StringLiteral) {
            $value = $argument->expression->getStringContentsText();

            return strlen($value) <= self::MAX_ARGUMENT_LENGTH
                ? new ResourceAttributeArgumentFact($name, 'string', $value)
                : new ResourceAttributeArgumentFact($name, 'dynamic', null);
        }

        $raw = trim($argument->expression->getText());
        if ($raw === '' || strlen($raw) > self::MAX_ARGUMENT_LENGTH) {
            return new ResourceAttributeArgumentFact($name, 'dynamic', null);
        }
        if ($argument->expression instanceof NumericLiteral) {
            return new ResourceAttributeArgumentFact($name, 'number', $raw);
        }

        return match (strtolower($raw)) {
            'true' => new ResourceAttributeArgumentFact($name, 'boolean', true),
            'false' => new ResourceAttributeArgumentFact($name, 'boolean', false),
            'null' => new ResourceAttributeArgumentFact($name, 'null', null),
            default => new ResourceAttributeArgumentFact($name, 'dynamic', null),
        };
    }

    /** @return 'embed'|'link'|null */
    private function relationKind(Attribute $attribute): ?string
    {
        return match ($this->attributeFqn($attribute)) {
            self::EMBED_FQN => 'embed',
            self::LINK_FQN => 'link',
            default => null,
        };
    }

    private function stringArgument(Attribute $attribute, string $name, int $position, string $source): ?string
    {
        if (!$attribute->argumentExpressionList instanceof DelimitedList) {
            return null;
        }
        $arguments = iterator_to_array($attribute->argumentExpressionList->getElements(), false);
        foreach ($arguments as $argument) {
            if (
                !$argument instanceof ArgumentExpression
                || !$argument->name instanceof Token
                || $argument->name->getText($source) !== $name
            ) {
                continue;
            }

            return $argument->expression instanceof StringLiteral
                ? $argument->expression->getStringContentsText()
                : null;
        }

        $argument = $arguments[$position] ?? null;
        if (!$argument instanceof ArgumentExpression || $argument->name instanceof Token) {
            return null;
        }

        return $argument->expression instanceof StringLiteral
            ? $argument->expression->getStringContentsText()
            : null;
    }

    private function hasArgument(Attribute $attribute, string $name, int $position, string $source): bool
    {
        if (!$attribute->argumentExpressionList instanceof DelimitedList) {
            return false;
        }
        $arguments = iterator_to_array($attribute->argumentExpressionList->getElements(), false);
        foreach ($arguments as $argument) {
            if (
                $argument instanceof ArgumentExpression
                && $argument->name instanceof Token
                && $argument->name->getText($source) === $name
            ) {
                return true;
            }
        }

        $argument = $arguments[$position] ?? null;

        return $argument instanceof ArgumentExpression && !$argument->name instanceof Token;
    }

    private function targetUri(string $target, ResourceUri $source): ?ResourceUri
    {
        if (preg_match('#^/(?!/)#', $target) === 1) {
            $target = sprintf('%s://self%s', $source->scheme(), $target);
        }
        $uri = ResourceUri::fromString($target);
        if ($uri === null) {
            return null;
        }
        foreach (explode('/', $uri->path()) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $uri;
    }
}
