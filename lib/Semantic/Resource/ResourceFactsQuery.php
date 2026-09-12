<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\DelimitedList;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\MethodDeclaration;
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
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Util\PhpClassDeclaration;

/**
 * Reads statically knowable facts from one resolved Resource class.
 */
final class ResourceFactsQuery
{
    private const MAX_PHP_BYTES = 2097152;
    private const EMBED_FQN = 'BEAR\Resource\Annotation\Embed';
    private const LINK_FQN = 'BEAR\Resource\Annotation\Link';

    public function __construct(
        private ResourceQuery $resourceQuery = new ResourceQuery(),
        private Parser $parser = new Parser(),
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

        $class = PhpClassDeclaration::findInSource($source, $path->value->absolute, $this->parser);
        if (!$class instanceof ClassDeclaration) {
            return SemanticResult::parseError();
        }

        $methods = [];
        $relations = [];
        foreach ($class->classMembers->getChildNodes() as $member) {
            if (!$member instanceof MethodDeclaration || !$this->isResourceMethod($member)) {
                continue;
            }

            $method = new ResourceMethodFact($member->getName(), $this->parameters($member));
            $methods[] = $method;
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

        return SemanticResult::ok(new ResourceFacts($resource, $methods, $relations));
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
            $target = $this->namedStringArgument($attribute, $targetArgument, $source);
            if ($target === null) {
                continue;
            }
            $targetUri = $this->targetUri($target, $resource->uri);
            if ($targetUri === null) {
                continue;
            }

            $targetMethod = 'onGet';
            if ($kind === 'link') {
                $linkMethod = $this->namedStringArgument($attribute, 'method', $source);
                if ($this->hasNamedArgument($attribute, 'method', $source) && $linkMethod === null) {
                    $targetMethod = null;
                } elseif ($linkMethod !== null && $linkMethod !== '') {
                    $targetMethod = 'on' . ucfirst(strtolower($linkMethod));
                }
            }

            $relations[] = new ResourceRelationFact(
                $kind,
                $this->namedStringArgument($attribute, 'rel', $source) ?? '',
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
    private function attributes(MethodDeclaration $method): iterable
    {
        foreach (is_array($method->attributes) ? $method->attributes : [] as $group) {
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

    /** @return 'embed'|'link'|null */
    private function relationKind(Attribute $attribute): ?string
    {
        if (!$attribute->name instanceof QualifiedName) {
            return null;
        }
        $resolved = $attribute->name->getResolvedName();
        $fqn = $resolved === null ? null : ltrim((string) $resolved, '\\');

        return match ($fqn) {
            self::EMBED_FQN => 'embed',
            self::LINK_FQN => 'link',
            default => null,
        };
    }

    private function namedStringArgument(Attribute $attribute, string $name, string $source): ?string
    {
        if (!$attribute->argumentExpressionList instanceof DelimitedList) {
            return null;
        }
        foreach ($attribute->argumentExpressionList->getElements() as $argument) {
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

        return null;
    }

    private function hasNamedArgument(Attribute $attribute, string $name, string $source): bool
    {
        if (!$attribute->argumentExpressionList instanceof DelimitedList) {
            return false;
        }
        foreach ($attribute->argumentExpressionList->getElements() as $argument) {
            if (
                $argument instanceof ArgumentExpression
                && $argument->name instanceof Token
                && $argument->name->getText($source) === $name
            ) {
                return true;
            }
        }

        return false;
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
