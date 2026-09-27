<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di\Composition;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\ArrayElement;
use Microsoft\PhpParser\Node\Expression\AnonymousFunctionCreationExpression;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\Expression\ArrayCreationExpression;
use Microsoft\PhpParser\Node\Expression\ArrowFunctionCreationExpression;
use Microsoft\PhpParser\Node\Expression\AssignmentExpression;
use Microsoft\PhpParser\Node\Expression\BinaryExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\CastExpression;
use Microsoft\PhpParser\Node\Expression\ListIntrinsicExpression;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\Expression\ObjectCreationExpression;
use Microsoft\PhpParser\Node\Expression\ParenthesizedExpression;
use Microsoft\PhpParser\Node\Expression\ScopedPropertyAccessExpression;
use Microsoft\PhpParser\Node\Expression\SubscriptExpression;
use Microsoft\PhpParser\Node\Expression\TernaryExpression;
use Microsoft\PhpParser\Node\Expression\ThrowExpression;
use Microsoft\PhpParser\Node\Expression\UnaryOpExpression;
use Microsoft\PhpParser\Node\Expression\Variable;
use Microsoft\PhpParser\Node\Expression\YieldExpression;
use Microsoft\PhpParser\Node\ForeachValue;
use Microsoft\PhpParser\Node\MethodDeclaration;
use Microsoft\PhpParser\Node\NumericLiteral;
use Microsoft\PhpParser\Node\Parameter;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\ReservedWord;
use Microsoft\PhpParser\Node\Statement\CompoundStatementNode;
use Microsoft\PhpParser\Node\Statement\EmptyStatement;
use Microsoft\PhpParser\Node\Statement\ExpressionStatement;
use Microsoft\PhpParser\Node\Statement\ForeachStatement;
use Microsoft\PhpParser\Node\Statement\IfStatementNode;
use Microsoft\PhpParser\Node\Statement\ReturnStatement;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Token;

/**
 * Interprets Ray.Di module classes from saved source; nothing is executed.
 *
 * Only the constructs module composition needs are understood: constructors,
 * `configure()`, same-object methods, property and local assignments, `new`
 * modules, `bind()` chains, `install()`, `override()`, `rename()`, `foreach` over
 * known arrays, and branches whose condition is known or which only throw.
 * Anything else becomes an explicit unknown with its location instead of a guess.
 */
final class ModuleInterpreter
{
    public const ABSTRACT_MODULE = 'Ray\\Di\\AbstractModule';

    private const MAX_DEPTH = 64;

    private const APP_META = 'BEAR\\AppMeta\\AbstractAppMeta';

    private const RESOURCE_OBJECT = 'BEAR\\Resource\\ResourceObject';

    /** @var list<array{reason: string, path: string, line: int, module: string}> */
    public array $unknowns = [];

    /** @var list<BindValue> */
    private array $pendingBinds = [];

    private int $depth = 0;

    private ?bool $rebindsMultiBindings = null;

    /**
     * @param array<string, string>|null $environment an explicit environment profile: listed
     *        variables have these values and every other variable is unset; null leaves every
     *        environment value unknown. Real environment files are never read.
     */
    public function __construct(
        private readonly ClassSourceIndex $classes,
        private readonly ?array $environment = null,
    ) {
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    public function newObject(string $class, array $arguments = []): mixed
    {
        $source = $this->classes->find($class);
        if ($source === null) {
            return new ObjectValue($class);
        }
        $object = new ObjectValue($source->name);
        $this->initializeProperties($object);
        if ($this->isSubclassOf($source->name, self::ABSTRACT_MODULE)) {
            $this->callMethod($object, '__construct', $arguments, null);
            $this->containerOf($object);

            return $object;
        }
        $constructor = $this->findMethod($source->name, '__construct');
        if ($constructor !== null) {
            $this->invoke($constructor[0], $constructor[1], $object, $arguments);
        }

        return $object;
    }

    public function newAppMeta(string $name, string $context, string $appDir): ObjectValue
    {
        return new ObjectValue('BEAR\\AppMeta\\Meta', [
            'name' => $name,
            'appDir' => $appDir,
            'buildDir' => $appDir . '/var/build/' . $context,
            'tmpDir' => $appDir . '/var/tmp/' . $context,
            'logDir' => $appDir . '/var/log/' . $context,
        ]);
    }

    public function newContainer(): EmulatedContainer
    {
        $this->rebindsMultiBindings ??= $this->classes->find('Ray\\Di\\Container')
            ?->method('bindMergedMultiBindings') !== null;

        return new EmulatedContainer($this->rebindsMultiBindings);
    }

    public function containerOf(ObjectValue $module): EmulatedContainer
    {
        if ($module->container === null) {
            // Ray.Di activates a module lazily when its container is first requested.
            $module->container = $this->newContainer();
            $this->callMethod($module, 'configure', [], null);
        }

        return $module->container;
    }

    public function isSubclassOf(string $class, string $parent): bool
    {
        $seen = [];
        for ($current = $class; $current !== null && !isset($seen[strtolower($current)]);) {
            if (strcasecmp($current, $parent) === 0) {
                return true;
            }
            $seen[strtolower($current)] = true;
            $current = $this->classes->find($current)?->parent;
        }

        return false;
    }

    public function isA(string $class, string $type): bool
    {
        if ($this->isSubclassOf($class, $type)) {
            return true;
        }
        $pending = [$class];
        $seen = [];
        while (($current = array_pop($pending)) !== null) {
            if (isset($seen[strtolower($current)])) {
                continue;
            }
            $seen[strtolower($current)] = true;
            if (strcasecmp($current, $type) === 0) {
                return true;
            }
            $source = $this->classes->find($current);
            if ($source === null) {
                continue;
            }
            array_push($pending, ...$source->interfaces);
            if ($source->parent !== null) {
                $pending[] = $source->parent;
            }
        }

        return false;
    }

    public function isInstantiable(string $class): bool
    {
        $internal = $this->classes->internal($class);
        if ($internal !== null) {
            return $internal->isInstantiable();
        }
        $source = $this->classes->find($class);

        return $source !== null && $source->isClass() && !$source->isAbstract();
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    public function callMethod(ObjectValue $object, string $method, array $arguments, ?Frame $caller): mixed
    {
        if ($this->isAppMeta($object)) {
            return $this->appMetaBuiltin($object, $method, $caller);
        }
        $found = $this->findMethod($object->class, $method);
        if ($this->isModule($object) && ($found === null || strcasecmp($found[0]->name, self::ABSTRACT_MODULE) === 0)) {
            return $this->moduleBuiltin($object, $method, $arguments, $caller);
        }
        if ($found === null) {
            return new UnknownValue();
        }

        return $this->invoke($found[0], $found[1], $object, $arguments);
    }

    /**
     * The declaration a call on `$class` would reach, walking traits and parents.
     *
     * @return array{ClassSource, MethodDeclaration}|null
     */
    public function findMethodIn(string $class, string $method): ?array
    {
        return $this->findMethod($class, $method);
    }

    /**
     * @return array{ClassSource, MethodDeclaration}|null
     */
    private function findMethod(string $class, string $method): ?array
    {
        $seen = [];
        for ($current = $class; $current !== null && !isset($seen[strtolower($current)]);) {
            $seen[strtolower($current)] = true;
            $source = $this->classes->find($current);
            if ($source === null) {
                return null;
            }
            $declaration = $source->method($method);
            if ($declaration !== null) {
                return [$source, $declaration];
            }
            foreach ($source->traits() as $trait) {
                $traitSource = $this->classes->find($trait);
                $traitMethod = $traitSource?->method($method);
                if ($traitSource !== null && $traitMethod !== null) {
                    return [$traitSource, $traitMethod];
                }
            }
            $current = $source->parent;
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private function invoke(
        ClassSource $declaring,
        MethodDeclaration $method,
        ?ObjectValue $object,
        array $arguments,
    ): mixed {
        if ($this->depth >= self::MAX_DEPTH) {
            $this->unknown('recursion_limit', $method, $declaring, $object);

            return new UnknownValue();
        }
        ++$this->depth;
        try {
            $frame = new Frame($declaring, $object);
            $this->bindParameters($method, $arguments, $frame);
            $body = $method->compoundStatementOrSemicolon;
            if (!$body instanceof CompoundStatementNode) {
                return null;
            }
            try {
                $this->executeStatements($body->statements, $frame);
            } catch (ReturnSignal $return) {
                return $frame->yields ?? $return->value;
            } catch (ThrowSignal) {
                $this->unknown('throw_reached', $method, $declaring, $object);

                return new UnknownValue();
            }

            return $frame->yields;
        } finally {
            --$this->depth;
        }
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private function bindParameters(MethodDeclaration $method, array $arguments, Frame $frame): void
    {
        $position = 0;
        foreach (ParserNodes::elements($method->parameters) as $parameter) {
            if (!$parameter instanceof Parameter) {
                continue;
            }
            $name = (string) $parameter->getName();
            if ($parameter->dotDotDotToken !== null) {
                $rest = [];
                foreach ($arguments as $key => $value) {
                    if (is_int($key) && $key >= $position) {
                        $rest[] = $value;
                    }
                }
                $value = $rest;
            } elseif (array_key_exists($name, $arguments)) {
                $value = $arguments[$name];
            } elseif (array_key_exists($position, $arguments)) {
                $value = $arguments[$position];
            } elseif ($parameter->default !== null) {
                $value = $this->evaluate($parameter->default, $frame);
            } else {
                $value = new UnknownValue();
            }
            ++$position;
            $frame->locals[$name] = $value;
            if (
                $frame->object !== null
                && ($parameter->visibilityToken !== null || ($parameter->modifiers ?? []) !== [])
            ) {
                $frame->object->properties[$name] = $value;
            }
        }
    }

    private function initializeProperties(ObjectValue $object): void
    {
        $chain = [];
        $seen = [];
        for ($current = $object->class; $current !== null && !isset($seen[strtolower($current)]);) {
            $seen[strtolower($current)] = true;
            $source = $this->classes->find($current);
            if ($source === null) {
                break;
            }
            $chain[] = $source;
            $current = $source->parent;
        }
        foreach (array_reverse($chain) as $source) {
            $frame = new Frame($source, $object);
            foreach ($source->propertyDefaults() as $name => $default) {
                $object->properties[$name] = $default === null ? null : $this->evaluate($default, $frame);
            }
        }
    }

    /**
     * @param iterable<Node|Token> $statements
     */
    private function executeStatements(iterable $statements, Frame $frame): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Node) {
                $this->execute($statement, $frame);
            }
        }
    }

    private function execute(Node $statement, Frame $frame): void
    {
        $frame->statement = $statement;
        match (true) {
            $statement instanceof ExpressionStatement => $this->expressionStatement($statement, $frame),
            $statement instanceof ReturnStatement => throw new ReturnSignal(
                $statement->expression === null ? null : $this->evaluate($statement->expression, $frame),
            ),
            $statement instanceof CompoundStatementNode => $this->executeStatements($statement->statements, $frame),
            $statement instanceof IfStatementNode => $this->ifStatement($statement, $frame),
            $statement instanceof ForeachStatement => $this->foreachStatement($statement, $frame),
            $statement instanceof EmptyStatement => null,
            default => $this->unknown('statement_unsupported', $statement, $frame->declaring, $frame->object),
        };
    }

    private function expressionStatement(ExpressionStatement $statement, Frame $frame): void
    {
        $outer = $this->pendingBinds;
        $this->pendingBinds = [];
        try {
            $expression = ParserNodes::optional($statement->expression);
            if ($expression !== null) {
                $this->evaluate($expression, $frame);
            }
        } finally {
            $this->finishBinds();
            $this->pendingBinds = $outer;
        }
    }

    /**
     * Ray\Di\Bind registers an untargeted binding when the Bind object is destroyed,
     * which for a chained statement is the end of that statement.
     */
    private function finishBinds(): void
    {
        $pending = $this->pendingBinds;
        $this->pendingBinds = [];
        foreach ($pending as $bind) {
            if ($bind->untarget) {
                $bind->untarget = false;
                $bind->container->add(
                    $bind->interface . '-' . $bind->name,
                    ['kind' => 'dependency', 'target' => $bind->interface],
                    $bind->source,
                    $bind->origin,
                );
            }
        }
    }

    private function ifStatement(IfStatementNode $statement, Frame $frame): void
    {
        $branches = [[$statement->expression, $statement->statements]];
        foreach (ParserNodes::elements($statement->elseIfClauses) as $clause) {
            $branches[] = [$clause->expression, $clause->statements];
        }
        foreach ($branches as [$condition, $body]) {
            $value = $condition instanceof Node ? $this->evaluate($condition, $frame) : new UnknownValue();
            if ($value instanceof UnknownValue) {
                if ($this->onlyThrows($body)) {
                    // A guard that only throws cannot change the bindings of a successful boot.
                    continue;
                }
                $this->unknown('branch_condition_unknown', $statement, $frame->declaring, $frame->object);

                return;
            }
            if ($value instanceof ObjectValue || $value instanceof BindValue || (bool) $value) {
                $this->executeBody($body, $frame);

                return;
            }
        }
        if ($statement->elseClause !== null) {
            $this->executeBody($statement->elseClause->statements, $frame);
        }
    }

    private function executeBody(mixed $body, Frame $frame): void
    {
        if ($body instanceof Node) {
            $this->execute($body, $frame);
        } elseif (is_iterable($body)) {
            $this->executeStatements($body, $frame);
        }
    }

    private function onlyThrows(mixed $body): bool
    {
        $statements = match (true) {
            $body instanceof CompoundStatementNode => $body->statements,
            is_iterable($body) => $body,
            default => [$body],
        };
        $nodes = [];
        foreach ($statements as $node) {
            if ($node instanceof Node) {
                $nodes[] = $node;
            }
        }

        return count($nodes) === 1
            && $nodes[0] instanceof ExpressionStatement
            && $nodes[0]->expression instanceof ThrowExpression;
    }

    private function foreachStatement(ForeachStatement $statement, Frame $frame): void
    {
        $collection = $this->evaluate($statement->forEachCollectionName, $frame);
        if (!is_array($collection)) {
            $this->unknown('foreach_collection_unknown', $statement, $frame->declaring, $frame->object);

            return;
        }
        $keyTarget = $statement->foreachKey?->expression;
        $foreachValue = ParserNodes::optional($statement->foreachValue);
        $valueTarget = $foreachValue instanceof ForeachValue ? ParserNodes::optional($foreachValue->expression) : null;
        foreach ($collection as $key => $value) {
            if ($keyTarget instanceof Node) {
                $this->assign($keyTarget, $key, $frame);
            }
            if ($valueTarget !== null) {
                $this->assign($valueTarget, $value, $frame);
            }
            $this->executeBody($statement->statements, $frame);
        }
    }

    public function evaluate(Node $node, Frame $frame): mixed
    {
        return match (true) {
            $node instanceof ParenthesizedExpression => $this->evaluate($node->expression, $frame),
            $node instanceof StringLiteral => $this->stringLiteral($node, $frame),
            $node instanceof NumericLiteral => $this->numeric($node->getText()),
            $node instanceof ReservedWord => match (strtolower($node->getText())) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => new UnknownValue(),
            },
            $node instanceof ArrayCreationExpression => $this->arrayLiteral($node, $frame),
            $node instanceof Variable => $this->variable($node, $frame),
            $node instanceof ScopedPropertyAccessExpression => $this->scopedAccess($node, $frame),
            $node instanceof QualifiedName => $this->constantName($node, $frame),
            $node instanceof MemberAccessExpression => $this->propertyFetch($node, $frame),
            $node instanceof CallExpression => $this->call($node, $frame),
            $node instanceof ObjectCreationExpression => $this->newExpression($node, $frame),
            $node instanceof AssignmentExpression => $this->assignment($node, $frame),
            $node instanceof CastExpression => $this->cast($node, $frame),
            $node instanceof BinaryExpression => $this->binary($node, $frame),
            $node instanceof UnaryOpExpression => $this->unary($node, $frame),
            $node instanceof TernaryExpression => $this->ternary($node, $frame),
            $node instanceof SubscriptExpression => $this->subscript($node, $frame),
            $node instanceof YieldExpression => $this->yieldExpression($node, $frame),
            $node instanceof ThrowExpression => throw new ThrowSignal(),
            $node instanceof AnonymousFunctionCreationExpression,
            $node instanceof ArrowFunctionCreationExpression => new ObjectValue('Closure'),
            default => new UnknownValue(),
        };
    }

    private function stringLiteral(StringLiteral $node, Frame $frame): mixed
    {
        if (is_array($node->children)) {
            foreach ($node->children as $child) {
                if ($child instanceof Node) {
                    return new UnknownValue('string');
                }
            }
        }
        return PhpStringLiteral::decode($node);
    }

    private function numeric(string $literal): int|float
    {
        $literal = str_replace('_', '', strtolower(trim($literal)));
        if (str_starts_with($literal, '0x') || str_starts_with($literal, '0b') || str_starts_with($literal, '0o')) {
            return intval($literal, 0);
        }

        return strpbrk($literal, '.e') !== false ? (float) $literal : (int) $literal;
    }

    /** @return array<int|string, mixed>|UnknownValue */
    private function arrayLiteral(ArrayCreationExpression $node, Frame $frame): array|UnknownValue
    {
        $array = [];
        foreach (ParserNodes::elements($node->arrayElements) as $element) {
            if (!$element instanceof ArrayElement || $element->elementValue === null) {
                continue;
            }
            $value = $this->evaluate($element->elementValue, $frame);
            if ($element->dotDotDot !== null) {
                if (!is_array($value)) {
                    return new UnknownValue('array');
                }
                foreach ($value as $key => $item) {
                    is_int($key) ? $array[] = $item : $array[$key] = $item;
                }
                continue;
            }
            if ($element->elementKey === null) {
                $array[] = $value;
                continue;
            }
            $key = $this->evaluate($element->elementKey, $frame);
            if (!is_int($key) && !is_string($key)) {
                return new UnknownValue('array');
            }
            $array[$key] = $value;
        }

        return $array;
    }

    private function variable(Variable $node, Frame $frame): mixed
    {
        $name = (string) $node->getName();
        if ($name === 'this') {
            return $frame->object ?? new UnknownValue('object');
        }

        return array_key_exists($name, $frame->locals) ? $frame->locals[$name] : new UnknownValue();
    }

    private function scopedAccess(ScopedPropertyAccessExpression $node, Frame $frame): mixed
    {
        $class = $this->scopeClass($node->scopeResolutionQualifier, $frame);
        $member = $node->memberName instanceof Token ? $node->memberName->getText($frame->declaring->contents) : null;
        if ($class === null || !is_string($member)) {
            return new UnknownValue();
        }
        if (strtolower($member) === 'class') {
            return $class;
        }

        return $this->classConstant($class, $member);
    }

    private function scopeClass(mixed $qualifier, Frame $frame): ?string
    {
        if ($qualifier instanceof QualifiedName) {
            $text = strtolower($qualifier->getText());

            return match ($text) {
                'self' => $frame->declaring->name,
                'static' => $frame->object->class ?? $frame->declaring->name,
                'parent' => $frame->declaring->parent,
                default => ltrim((string) $qualifier->getResolvedName(), '\\') ?: null,
            };
        }
        if ($qualifier instanceof Node) {
            $value = $this->evaluate($qualifier, $frame);

            return match (true) {
                $value instanceof ObjectValue => $value->class,
                is_string($value) => ltrim($value, '\\'),
                default => null,
            };
        }

        return null;
    }

    private function classConstant(string $class, string $name): mixed
    {
        $pending = [$class];
        $seen = [];
        while (($current = array_shift($pending)) !== null) {
            if (isset($seen[strtolower($current)])) {
                continue;
            }
            $seen[strtolower($current)] = true;
            $source = $this->classes->find($current);
            if ($source === null) {
                continue;
            }
            $expression = $source->constant($name);
            if ($expression !== null) {
                return $this->evaluate($expression, new Frame($source, null));
            }
            if ($source->parent !== null) {
                $pending[] = $source->parent;
            }
            array_push($pending, ...$source->interfaces);
        }

        return new UnknownValue();
    }

    private function constantName(QualifiedName $node, Frame $frame): mixed
    {
        $file = $this->classes->absolutePath($frame->declaring->path);

        return match (strtoupper(ltrim($node->getText(), '\\'))) {
            '__DIR__' => dirname($file),
            '__FILE__' => $file,
            '__CLASS__' => $frame->declaring->name,
            'DIRECTORY_SEPARATOR' => '/',
            'PHP_EOL' => "\n",
            'TRUE' => true,
            'FALSE' => false,
            'NULL' => null,
            default => new UnknownValue(),
        };
    }

    private function propertyFetch(MemberAccessExpression $node, Frame $frame): mixed
    {
        $object = $this->evaluate($node->dereferencableExpression, $frame);
        $name = ParserNodes::text($node->memberName, $frame->declaring->contents);
        if (!$object instanceof ObjectValue || !is_string($name)) {
            return new UnknownValue();
        }

        return array_key_exists($name, $object->properties) ? $object->properties[$name] : new UnknownValue();
    }

    private function call(CallExpression $node, Frame $frame): mixed
    {
        $callable = $node->callableExpression;
        if ($callable instanceof MemberAccessExpression) {
            $receiver = $this->evaluate($callable->dereferencableExpression, $frame);
            $method = ParserNodes::text($callable->memberName, $frame->declaring->contents);
            if (!is_string($method)) {
                return new UnknownValue();
            }
            $arguments = $this->arguments($node, $frame);
            if ($receiver instanceof BindValue) {
                return $this->bindBuiltin($receiver, $method, $arguments, $node, $frame);
            }
            if ($receiver instanceof ObjectValue) {
                return $this->callMethod($receiver, $method, $arguments, $frame);
            }

            return new UnknownValue();
        }
        if ($callable instanceof ScopedPropertyAccessExpression) {
            return $this->staticCall($callable, $node, $frame);
        }
        if ($callable instanceof QualifiedName) {
            return $this->functionCall($callable, $this->arguments($node, $frame), $node, $frame);
        }

        return new UnknownValue();
    }

    private function staticCall(ScopedPropertyAccessExpression $callable, CallExpression $node, Frame $frame): mixed
    {
        $qualifier = $callable->scopeResolutionQualifier;
        $method = ParserNodes::text($callable->memberName, $frame->declaring->contents);
        $arguments = $this->arguments($node, $frame);
        $isParent = $qualifier instanceof QualifiedName && strtolower($qualifier->getText()) === 'parent';
        if ($isParent && is_string($method) && $frame->object !== null && $frame->declaring->parent !== null) {
            $found = $this->findMethod($frame->declaring->parent, $method);
            if ($found === null || strcasecmp($found[0]->name, self::ABSTRACT_MODULE) === 0) {
                return $this->moduleBuiltin($frame->object, $method, $arguments, $frame);
            }

            return $this->invoke($found[0], $found[1], $frame->object, $arguments);
        }
        $class = $this->scopeClass($qualifier, $frame);
        if ($class === null || !is_string($method)) {
            $this->unknown('static_call_unsupported', $node, $frame->declaring, $frame->object);

            return new UnknownValue();
        }
        $recipe = $this->staticRecipe($class, $method, $arguments);
        if ($recipe !== null) {
            return $recipe[0];
        }
        $found = $this->findMethod($class, $method);
        if ($found === null) {
            $this->unknown('static_call_unsupported', $node, $frame->declaring, $frame->object);

            return new UnknownValue();
        }
        $isStatic = $found[1]->isStatic();
        $result = $this->invoke($found[0], $found[1], $isStatic ? null : $frame->object, $arguments);
        if ($result instanceof UnknownValue) {
            $returns = $this->declaredReturnClass($found[0], $found[1], $class);
            if ($returns !== null) {
                return new ObjectValue($returns);
            }
        }

        return $result;
    }

    /**
     * Static helpers whose behavior depends on the filesystem or on Ray.Di internals.
     *
     * @param array<int|string, mixed> $arguments
     * @return array{mixed}|null
     */
    private function staticRecipe(string $class, string $method, array $arguments): ?array
    {
        $key = strtolower($class . '::' . $method);
        if ($key === 'ray\\di\\multibinder::newinstance') {
            $module = $arguments['module'] ?? $arguments[0] ?? null;
            if ($module instanceof ObjectValue && $this->isModule($module)) {
                $this->containerOf($module)->add(
                    EmulatedContainer::MULTI_BINDINGS_INDEX,
                    EmulatedContainer::multiBindingsInstance(),
                    $module->class,
                );
            }

            return [new ObjectValue('Ray\\Di\\MultiBinder')];
        }
        if ($key === 'ray\\mediaquery\\classesindirectories::list') {
            $directories = array_values(array_filter($arguments, 'is_string'));
            if (count($directories) !== count($arguments)) {
                return [new UnknownValue('array')];
            }

            return [(new DirectoryClassList($this->classes))(...$directories)];
        }

        return null;
    }

    private function declaredReturnClass(
        ClassSource $declaring,
        MethodDeclaration $method,
        string $calledClass,
    ): ?string {
        $types = $method->returnTypeList?->getElements() ?? [];
        $names = [];
        foreach ($types as $type) {
            if ($type instanceof QualifiedName) {
                $names[] = $type;
            }
        }
        if (count($names) !== 1 || $method->questionToken !== null) {
            return null;
        }
        $text = strtolower($names[0]->getText());

        return match ($text) {
            'self' => $declaring->name,
            'static' => $calledClass,
            default => ltrim((string) $names[0]->getResolvedName(), '\\') ?: null,
        };
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private function functionCall(QualifiedName $name, array $arguments, Node $node, Frame $frame): mixed
    {
        $function = strtolower(ltrim($name->getText(), '\\'));
        $known = static function (mixed ...$values): bool {
            foreach ($values as $value) {
                if ($value instanceof UnknownValue || $value instanceof ObjectValue || $value instanceof BindValue) {
                    return false;
                }
                if (is_array($value)) {
                    foreach ($value as $item) {
                        if (is_object($item)) {
                            return false;
                        }
                    }
                }
            }

            return true;
        };
        $arguments = array_values($arguments);
        if ($function === 'getenv') {
            // Environment values exist only at runtime unless an explicit profile is given.
            $name = $arguments[0] ?? null;
            if ($this->environment === null || !is_string($name)) {
                return new UnknownValue();
            }

            return $this->environment[$name] ?? false;
        }
        $pure = [
            'dirname', 'basename', 'sprintf', 'str_replace', 'implode', 'explode', 'array_merge',
            'array_reverse', 'ucwords', 'ucfirst', 'lcfirst', 'strtolower', 'strtoupper', 'array_keys',
            'array_values', 'count', 'in_array', 'trim',
            'rtrim', 'ltrim', 'str_starts_with', 'str_ends_with', 'str_contains', 'array_key_exists',
            'array_slice', 'array_combine', 'array_flip', 'array_unique', 'sort', 'strlen', 'substr',
        ];
        if (in_array($function, $pure, true)) {
            // An unknown argument is a runtime value, not an unsupported construct.
            return $known(...$arguments) ? $function(...$arguments) : new UnknownValue();
        }
        $typeChecks = ['is_string' => 'string', 'is_array' => 'array', 'is_int' => 'integer', 'is_bool' => 'boolean'];
        if (isset($typeChecks[$function])) {
            $value = $arguments[0] ?? null;

            return $value instanceof UnknownValue
                ? ($value->type === null ? new UnknownValue('boolean') : $value->type === $typeChecks[$function])
                : ($value instanceof ObjectValue ? false : gettype($value) === $typeChecks[$function]);
        }
        $returnTypes = [
            // Declared return types; these functions are never called. tempnam() is typed by
            // its success result, since a failed temp file leaves no bootable application.
            'sys_get_temp_dir' => 'string',
            'tempnam' => 'string',
            'uniqid' => 'string',
            'crc32' => 'integer',
        ];
        if (isset($returnTypes[$function])) {
            return new UnknownValue($returnTypes[$function]);
        }
        if (in_array($function, ['array_filter', 'array_map'], true)) {
            // Callbacks are not evaluated; the result is still an array.
            return new UnknownValue('array');
        }
        if ($function === 'preg_match') {
            // Captured groups are strings (without PREG_OFFSET_CAPTURE); the values stay unknown.
            $list = $node instanceof CallExpression ? $node->argumentExpressionList : null;
            $matches = ParserNodes::elements($list)[2] ?? null;
            $target = $matches instanceof ArgumentExpression ? ParserNodes::optional($matches->expression) : null;
            if ($target instanceof Variable && count($arguments) === 3) {
                $frame->locals[(string) $target->getName()] = new UnknownValue('array', elementType: 'string');
            }

            return new UnknownValue();
        }
        if (in_array($function, ['file_get_contents', 'json_decode', 'filter_var', 'realpath'], true)) {
            // Runtime file or string contents; the value stays unknown without reading it.
            return new UnknownValue();
        }
        if (in_array($function, ['class_exists', 'interface_exists'], true) && is_string($arguments[0] ?? null)) {
            $source = $this->classes->find($arguments[0]);

            $internal = $this->classes->internal($arguments[0]);
            if ($internal !== null) {
                return $function === 'class_exists' ? !$internal->isInterface() : $internal->isInterface();
            }

            // A missing Composer class can select a package context fallback. Symbols
            // outside those namespaces may belong to optional target extensions.
            return $source === null
                ? ($this->classes->hasSourceNamespace($arguments[0]) ? false : new UnknownValue('boolean'))
                : ($function === 'class_exists' ? $source->isClass() : $source->isInterface());
        }
        if ($function === 'is_a' || $function === 'is_subclass_of') {
            [$class, $type] = [$arguments[0] ?? null, $arguments[1] ?? null];
            $class = $class instanceof ObjectValue ? $class->class : $class;
            if (is_string($class) && is_string($type)) {
                return $function === 'is_a'
                    ? $this->isA($class, ltrim($type, '\\'))
                    : strcasecmp($class, $type) !== 0 && $this->isA($class, ltrim($type, '\\'));
            }
        }
        if ($function === 'assert') {
            return true;
        }
        $this->unknown('function_unsupported:' . $function, $node, $frame->declaring, $frame->object);

        return new UnknownValue();
    }

    /** @return array<int|string, mixed> */
    private function arguments(CallExpression|ObjectCreationExpression $node, Frame $frame): array
    {
        $arguments = [];
        foreach ($node->argumentExpressionList?->getElements() ?? [] as $argument) {
            if (!$argument instanceof ArgumentExpression || $argument->expression === null) {
                continue;
            }
            $value = $this->evaluate($argument->expression, $frame);
            if ($argument->dotDotDotToken !== null) {
                foreach (is_array($value) ? $value : [] as $key => $item) {
                    is_int($key) ? $arguments[] = $item : $arguments[$key] = $item;
                }
                continue;
            }
            if ($argument->name !== null) {
                $arguments[$argument->name->getText($frame->declaring->contents)] = $value;
                continue;
            }
            $arguments[] = $value;
        }

        return $arguments;
    }

    private function newExpression(ObjectCreationExpression $node, Frame $frame): mixed
    {
        $designator = $node->classTypeDesignator;
        $class = $designator instanceof Node ? $this->scopeClass($designator, $frame) : null;
        if ($class === null) {
            $this->unknown('new_class_unknown', $node, $frame->declaring, $frame->object);

            return new UnknownValue('object');
        }

        return $this->newObject($class, $this->arguments($node, $frame));
    }

    private function assignment(AssignmentExpression $node, Frame $frame): mixed
    {
        $operator = $node->operator->getText($frame->declaring->contents);
        $right = ParserNodes::optional($node->rightOperand);
        $value = $right === null ? new UnknownValue() : $this->evaluate($right, $frame);
        if ($operator === '.=' || $operator === '+=' || $operator === '??=') {
            $current = $this->evaluate($node->leftOperand, $frame);
            $value = match (true) {
                $operator === '??=' => $current === null ? $value : $current,
                $operator === '.=' && is_scalar($current) && is_scalar($value) => $current . $value,
                $operator === '+=' && is_array($current) && is_array($value) => $current + $value,
                default => new UnknownValue(),
            };
        }
        $this->assign($node->leftOperand, $value, $frame);

        return $value;
    }

    private function assign(Node $target, mixed $value, Frame $frame): void
    {
        if ($target instanceof Variable) {
            $frame->locals[(string) $target->getName()] = $value;

            return;
        }
        $property = $target instanceof MemberAccessExpression
            ? ParserNodes::text($target->memberName, $frame->declaring->contents)
            : null;
        if ($target instanceof MemberAccessExpression && $property !== null) {
            $object = $this->evaluate($target->dereferencableExpression, $frame);
            if ($object instanceof ObjectValue) {
                $object->properties[$property] = $value;
            }

            return;
        }
        if ($target instanceof SubscriptExpression) {
            $container = $this->evaluate($target->postfixExpression, $frame);
            $container = is_array($container) ? $container : ($container === null ? [] : null);
            if ($container === null) {
                return;
            }
            if ($target->accessExpression === null) {
                $container[] = $value;
            } else {
                $key = $this->evaluate($target->accessExpression, $frame);
                if (!is_int($key) && !is_string($key)) {
                    $this->assign($target->postfixExpression, new UnknownValue('array'), $frame);

                    return;
                }
                $container[$key] = $value;
            }
            $this->assign($target->postfixExpression, $container, $frame);

            return;
        }
        if ($target instanceof ArrayCreationExpression || $target instanceof ListIntrinsicExpression) {
            $elements = $target instanceof ArrayCreationExpression ? $target->arrayElements : $target->listElements;
            $index = 0;
            foreach (ParserNodes::elements($elements) as $element) {
                if (!$element instanceof ArrayElement || ParserNodes::optional($element->elementValue) === null) {
                    ++$index;
                    continue;
                }
                $key = $element->elementKey === null ? $index++ : $this->evaluate($element->elementKey, $frame);
                $item = is_array($value) && (is_int($key) || is_string($key)) && array_key_exists($key, $value)
                    ? $value[$key]
                    : new UnknownValue();
                $this->assign($element->elementValue, $item, $frame);
            }
        }
    }

    private function cast(CastExpression $node, Frame $frame): mixed
    {
        $type = strtolower(trim($node->castType->getText($frame->declaring->contents), "() \t"));
        $operand = ParserNodes::optional($node->operand);
        $value = $operand === null ? new UnknownValue() : $this->evaluate($operand, $frame);
        $gettype = match ($type) {
            'string', 'binary' => 'string',
            'int', 'integer' => 'integer',
            'bool', 'boolean' => 'boolean',
            'float', 'double', 'real' => 'double',
            'array' => 'array',
            'object' => 'object',
            default => null,
        };
        if (is_object($value) || $gettype === null || $gettype === 'object') {
            return new UnknownValue($gettype);
        }
        settype($value, $gettype);

        return $value;
    }

    private function binary(BinaryExpression $node, Frame $frame): mixed
    {
        $operator = strtolower($node->operator->getText($frame->declaring->contents));
        $left = $this->evaluate($node->leftOperand, $frame);
        if ($operator === '??') {
            return $left === null
                ? $this->evaluate($node->rightOperand, $frame)
                : $left;
        }
        if (($operator === '&&' || $operator === 'and') && !is_object($left) && !$left) {
            return false;
        }
        if (($operator === '||' || $operator === 'or') && !is_object($left) && $left) {
            return true;
        }
        if ($left instanceof UnknownValue && in_array($operator, ['&&', 'and', '||', 'or'], true)) {
            // `$flag && $this->install(...)` is a branch; a pure condition is left to its `if`,
            // which also knows whether the branch only throws.
            if ($this->hasSideEffectCall(ParserNodes::optional($node->rightOperand))) {
                $this->unknown('branch_condition_unknown', $node, $frame->declaring, $frame->object);

                return new UnknownValue('boolean');
            }
            return new UnknownValue('boolean');
        }
        $right = $this->evaluate($node->rightOperand, $frame);
        if (is_object($left) || is_object($right) || (is_array($left) && $operator === '.')) {
            return match ($operator) {
                '.' => new UnknownValue('string'),
                '===', '!==', '==', '!=', '<', '>', '<=', '>=', '&&', '||', 'instanceof' => new UnknownValue('boolean'),
                default => new UnknownValue(),
            };
        }

        return match ($operator) {
            '.' => $left . $right,
            '===' => $left === $right,
            '!==' => $left !== $right,
            '==' => $left == $right,
            '!=' => $left != $right,
            '&&', 'and' => $left && $right,
            '||', 'or' => $left || $right,
            '+' => match (true) {
                is_array($left) && is_array($right), is_numeric($left) && is_numeric($right) => $left + $right,
                default => new UnknownValue(),
            },
            default => new UnknownValue(),
        };
    }

    private function unary(UnaryOpExpression $node, Frame $frame): mixed
    {
        $operator = $node->operator->getText($frame->declaring->contents);
        $value = $this->evaluate($node->operand, $frame);
        if (is_object($value)) {
            return new UnknownValue($operator === '!' ? 'boolean' : null);
        }

        return match ($operator) {
            '!' => !$value,
            '-' => is_numeric($value) ? -$value : new UnknownValue(),
            '+' => is_numeric($value) ? +$value : new UnknownValue(),
            default => new UnknownValue(),
        };
    }

    private function ternary(TernaryExpression $node, Frame $frame): mixed
    {
        $condition = $this->evaluate($node->condition, $frame);
        if (is_object($condition)) {
            return $this->unknownTernary($node, $frame);
        }
        if ($node->ifExpression === null) {
            return $condition ?: $this->evaluate($node->elseExpression, $frame);
        }

        return $this->evaluate($condition ? $node->ifExpression : $node->elseExpression, $frame);
    }

    /**
     * Keeps what both arms agree on: `is_string($x) ? $x : ''` is a string whatever `$x` is.
     * Arms that call methods are runtime branches and are recorded, not guessed.
     */
    private function unknownTernary(TernaryExpression $node, Frame $frame): mixed
    {
        foreach ([$node->ifExpression, $node->elseExpression] as $arm) {
            if ($this->hasSideEffectCall(ParserNodes::optional($arm))) {
                $before = count($this->unknowns);
                $this->unknown('branch_condition_unknown', $node, $frame->declaring, $frame->object);

                return new UnknownValue(reported: count($this->unknowns) > $before);
            }
        }
        $narrowed = $this->narrowedType($node, $frame);
        $types = [];
        $arms = [ParserNodes::optional($node->ifExpression) ?? $node->condition, $node->elseExpression];
        foreach ($arms as $position => $arm) {
            $value = $arm instanceof Node ? $this->evaluate($arm, $frame) : new UnknownValue();
            $types[] = $position === 0 && $narrowed !== null ? $narrowed : $this->typeOf($value);
        }

        return new UnknownValue($types[0] !== null && $types[0] === $types[1] ? $types[0] : null);
    }

    /**
     * Method and static calls can install or bind; plain function calls cannot.
     */
    private function hasSideEffectCall(?Node $node): bool
    {
        if ($node === null) {
            return false;
        }
        $calls = $node instanceof CallExpression ? [$node] : [];
        foreach ($node->getDescendantNodes() as $descendant) {
            if ($descendant instanceof CallExpression) {
                $calls[] = $descendant;
            }
        }
        foreach ($calls as $call) {
            if (!$call->callableExpression instanceof QualifiedName) {
                return true;
            }
        }

        return $node instanceof ObjectCreationExpression
            || $node->getFirstDescendantNode(ObjectCreationExpression::class) !== null;
    }

    private function narrowedType(TernaryExpression $node, Frame $frame): ?string
    {
        $condition = $node->condition;
        if (
            !$condition instanceof CallExpression
            || !$condition->callableExpression instanceof QualifiedName
            || !$node->ifExpression instanceof Variable
        ) {
            return null;
        }
        $type = match (strtolower($condition->callableExpression->getText())) {
            'is_string' => 'string',
            'is_array' => 'array',
            'is_int' => 'integer',
            'is_bool' => 'boolean',
            default => null,
        };
        $arguments = $condition->argumentExpressionList?->getElements() ?? [];
        foreach ($arguments as $argument) {
            return $argument instanceof ArgumentExpression
                && $argument->expression instanceof Variable
                && $argument->expression->getName() === $node->ifExpression->getName()
                ? $type
                : null;
        }

        return null;
    }

    private function typeOf(mixed $value): ?string
    {
        return match (true) {
            $value instanceof UnknownValue => $value->type,
            $value instanceof ObjectValue, $value instanceof BindValue => 'object',
            default => gettype($value),
        };
    }

    private function subscript(SubscriptExpression $node, Frame $frame): mixed
    {
        $container = $this->evaluate($node->postfixExpression, $frame);
        $key = $node->accessExpression instanceof Node ? $this->evaluate($node->accessExpression, $frame) : null;
        if (is_array($container) && (is_int($key) || is_string($key)) && array_key_exists($key, $container)) {
            return $container[$key];
        }
        if ($container instanceof UnknownValue && $container->elementType !== null) {
            return new UnknownValue($container->elementType);
        }

        return new UnknownValue();
    }

    private function yieldExpression(YieldExpression $node, Frame $frame): mixed
    {
        $element = $node->arrayElement;
        if ($element instanceof ArrayElement && ParserNodes::optional($element->elementValue) !== null) {
            $frame->yields ??= [];
            $frame->yields[] = $this->evaluate($element->elementValue, $frame);
        }

        return null;
    }

    private function isModule(ObjectValue $value): bool
    {
        return $this->isSubclassOf($value->class, self::ABSTRACT_MODULE);
    }

    private function isAppMeta(ObjectValue $value): bool
    {
        return $value->class === 'BEAR\\AppMeta\\Meta' || $this->isSubclassOf($value->class, self::APP_META);
    }

    /**
     * Ray\Di\AbstractModule behavior; its source is never interpreted.
     *
     * @param array<int|string, mixed> $arguments
     */
    private function moduleBuiltin(ObjectValue $module, string $method, array $arguments, ?Frame $caller): mixed
    {
        $argument = static fn (int $position, string $name): mixed
            => $arguments[$name] ?? $arguments[$position] ?? null;
        switch (strtolower($method)) {
            case '__construct':
                $last = $argument(0, 'module');
                $module->lastModule = $last instanceof ObjectValue ? $last : null;
                $module->container = $this->newContainer();
                $this->callMethod($module, 'configure', [], $caller);
                if ($last instanceof ObjectValue && $this->isModule($last)) {
                    $module->container->merge(
                        $this->containerOf($last),
                        $this->edge('constructor_chain', $module, $last, $caller),
                        'kept_outer_module_binding',
                    );
                }

                return null;
            case 'configure':
                return null;
            case 'install':
                $installed = $argument(0, 'module');
                if ($installed instanceof ObjectValue && $this->isModule($installed)) {
                    $this->containerOf($module)->merge(
                        $this->containerOf($installed),
                        $this->edge('install', $module, $installed, $caller),
                    );
                } elseif (!$installed instanceof UnknownValue || !$installed->reported) {
                    $this->callerUnknown('install_module_unknown', $caller, $module);
                }

                return null;
            case 'override':
                $override = $argument(0, 'module');
                if ($override instanceof ObjectValue && $this->isModule($override)) {
                    $overrideContainer = $this->containerOf($override);
                    $edge = $this->edge('override', $module, $override, $caller);
                    $overrideContainer->traceThrough($edge);
                    $override->via = [$edge, ...$override->via];
                    $overrideContainer->merge($this->containerOf($module), reason: 'overridden');
                    $module->container = $overrideContainer;
                } elseif (!$override instanceof UnknownValue || !$override->reported) {
                    $this->callerUnknown('override_module_unknown', $caller, $module);
                }

                return null;
            case 'bind':
                $interface = $argument(0, 'interface') ?? '';
                if (!is_string($interface)) {
                    $this->callerUnknown('bind_interface_unknown', $caller, $module);

                    return new UnknownValue('object');
                }
                $interface = ltrim($interface, '\\');
                $container = $this->containerOf($module);
                $untarget = $interface !== ''
                    && $this->isInstantiable($interface)
                    && !isset($container->bindings[$interface . '-']);
                $origin = $this->origin($module, $caller);
                $bind = new BindValue($container, $interface, $module->class, $untarget, $origin);
                $this->pendingBinds[] = $bind;

                return $bind;
            case 'rename':
                return $this->rename($module, $arguments, $caller);
            case 'getcontainer':
                return new UnknownValue('object');
            case 'bindinterceptor':
            case 'bindpriorityinterceptor':
                // Pointcuts are not logged, but each interceptor is bound as a singleton.
                $interceptors = $argument(2, 'interceptors');
                if (!is_array($interceptors)) {
                    $this->callerUnknown('interceptors_unknown', $caller, $module);

                    return null;
                }
                $container = $this->containerOf($module);
                foreach ($interceptors as $interceptor) {
                    if (!is_string($interceptor)) {
                        $this->callerUnknown('interceptor_unknown', $caller, $module);
                        continue;
                    }
                    $interceptor = ltrim($interceptor, '\\');
                    $isClass = $this->classes->find($interceptor)?->isClass() ?? false;
                    if ($isClass || strtolower($method) === 'bindpriorityinterceptor') {
                        $container->add(
                            $interceptor . '-',
                            ['kind' => 'dependency', 'target' => $interceptor],
                            $module->class,
                            $this->origin($module, $caller),
                        );
                    }
                }

                return null;
            default:
                $this->callerUnknown('module_method_unknown:' . $method, $caller, $module);

                return new UnknownValue();
        }
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private function rename(ObjectValue $module, array $arguments, ?Frame $caller): mixed
    {
        $interface = $arguments['interface'] ?? $arguments[0] ?? null;
        $newName = $arguments['newName'] ?? $arguments[1] ?? null;
        $sourceName = $arguments['sourceName'] ?? $arguments[2] ?? '';
        $target = $arguments['targetInterface'] ?? $arguments[3] ?? '';
        if (!is_string($interface) || !is_string($newName) || !is_string($sourceName) || !is_string($target)) {
            $this->callerUnknown('rename_arguments_unknown', $caller, $module);

            return null;
        }
        $target = $target !== '' ? $target : $interface;
        if ($module->lastModule !== null) {
            $moved = $this->containerOf($module->lastModule)
                ->move($interface . '-' . $sourceName, $target . '-' . $newName);
            if (!$moved) {
                $this->callerUnknown('rename_source_unbound', $caller, $module);
            }
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private function bindBuiltin(BindValue $bind, string $method, array $arguments, Node $node, Frame $frame): mixed
    {
        $first = $arguments[array_key_first($arguments) ?? 0] ?? null;
        $index = static fn (): string => $bind->interface . '-' . $bind->name;
        switch ($method) {
            case 'annotatedWith':
                if (!is_string($first)) {
                    $this->unknown('qualifier_unknown', $node, $frame->declaring, $frame->object);
                    $bind->untarget = false;

                    return new UnknownValue('object');
                }
                $bind->name = ltrim($first, '\\') === $first ? $first : ltrim($first, '\\');

                return $bind;
            case 'in':
                return $bind;
            case 'to':
            case 'toConstructor':
            case 'toProvider':
                $bind->untarget = false;
                $class = $method === 'toProvider'
                    ? ($arguments['provider'] ?? $arguments[0] ?? null)
                    : ($arguments['class'] ?? $arguments[0] ?? null);
                if (!is_string($class)) {
                    $this->unknown('target_unknown', $node, $frame->declaring, $frame->object);

                    return $bind;
                }
                $dependency = [
                    'kind' => $method === 'toProvider' ? 'provider' : 'dependency',
                    'target' => ltrim($class, '\\'),
                ];
                $names = $arguments['name'] ?? $arguments[1] ?? null;
                if ($method === 'toConstructor' && (is_string($names) || is_array($names))) {
                    // Ray\Di\Name for the constructor parameters; unknown values stay unmapped.
                    $dependency['names'] = is_array($names) ? array_filter($names, 'is_string') : $names;
                }
                $bind->container->add($index(), $dependency, $bind->source, $bind->origin);

                return $bind;
            case 'toInstance':
                $bind->untarget = false;
                $dependency = $this->instanceDependency($arguments['instance'] ?? $first);
                $bind->container->add($index(), $dependency, $bind->source, $bind->origin);

                return $bind;
            case 'toNull':
                $bind->untarget = false;
                $bind->container->add(
                    $index(),
                    ['kind' => 'null_object', 'target' => null],
                    $bind->source,
                    $bind->origin,
                );

                return $bind;
            default:
                $this->unknown('bind_method_unknown:' . $method, $node, $frame->declaring, $frame->object);

                return $bind;
        }
    }

    /** @return array{kind: string, target: ?string} */
    private function instanceDependency(mixed $value): array
    {
        return match (true) {
            $value instanceof UnknownValue => ['kind' => $value->type ?? 'unknown', 'target' => null],
            $value instanceof ObjectValue => ['kind' => 'object', 'target' => $value->class],
            $value instanceof BindValue => ['kind' => 'object', 'target' => 'Ray\\Di\\Bind'],
            default => ['kind' => gettype($value), 'target' => null],
        };
    }

    private function appMetaBuiltin(ObjectValue $meta, string $method, ?Frame $caller): mixed
    {
        if (strtolower($method) !== 'getresourcelistgenerator') {
            $this->callerUnknown('app_meta_method_unknown:' . $method, $caller, $meta);

            return new UnknownValue();
        }
        $name = $meta->properties['name'] ?? null;
        $appDir = $meta->properties['appDir'] ?? null;
        if (!is_string($name) || !is_string($appDir)) {
            return new UnknownValue('array');
        }

        $list = new ResourceClassList($this->classes, $this);

        return $list($name . '\\Resource', $appDir . '/src/Resource', self::RESOURCE_OBJECT);
    }

    private function origin(ObjectValue $module, ?Frame $caller): BindingOrigin
    {
        return new BindingOrigin(
            $module->class,
            $caller?->declaring->path,
            $caller?->statement === null ? null
                : $this->lineOf($caller->declaring->contents, $caller->statement->getStartPosition()),
            $module->via,
        );
    }

    private function edge(string $operation, ObjectValue $module, ObjectValue $target, ?Frame $caller): ModuleEdge
    {
        $origin = $this->origin($module, $caller);

        return new ModuleEdge($operation, $module->class, $target->class, $origin->path, $origin->line);
    }

    public function callerUnknown(string $reason, ?Frame $caller, ObjectValue $module): void
    {
        $this->unknowns[] = [
            'reason' => $reason,
            'path' => $caller?->declaring->path ?? '',
            'line' => $caller?->statement === null
                ? 0
                : $this->lineOf($caller->declaring->contents, $caller->statement->getStartPosition()),
            'module' => $module->class,
        ];
    }

    private function unknown(string $reason, Node $node, ClassSource $declaring, ?ObjectValue $module): void
    {
        if (!$this->isSubclassOf($declaring->name, self::ABSTRACT_MODULE)) {
            // Unknowns inside helper classes surface as unknown values where modules use them.
            return;
        }
        $this->unknowns[] = [
            'reason' => $reason,
            'path' => $declaring->path,
            'line' => $this->lineOf($declaring->contents, $node->getStartPosition()),
            'module' => $module->class ?? $declaring->name,
        ];
    }

    private function lineOf(string $contents, int $offset): int
    {
        return substr_count($contents, "\n", 0, min($offset, strlen($contents))) + 1;
    }
}
