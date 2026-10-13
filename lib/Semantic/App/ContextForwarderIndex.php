<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\App;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\CatchClause;
use Microsoft\PhpParser\Node\Expression\AnonymousFunctionCreationExpression;
use Microsoft\PhpParser\Node\Expression\ArrowFunctionCreationExpression;
use Microsoft\PhpParser\Node\Expression\AssignmentExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\PostfixUpdateExpression;
use Microsoft\PhpParser\Node\Expression\PrefixUpdateExpression;
use Microsoft\PhpParser\Node\Expression\ScriptInclusionExpression;
use Microsoft\PhpParser\Node\Expression\Variable;
use Microsoft\PhpParser\Node\ForeachKey;
use Microsoft\PhpParser\Node\ForeachValue;
use Microsoft\PhpParser\Node\MethodDeclaration;
use Microsoft\PhpParser\Node\Parameter;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Node\Statement\FunctionStaticDeclaration;
use Microsoft\PhpParser\Node\Statement\GlobalDeclaration;
use Microsoft\PhpParser\Node\Statement\ReturnStatement;
use Microsoft\PhpParser\Node\Statement\UnsetStatement;
use Microsoft\PhpParser\Node\UseVariableName;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ParserNodes;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceAccessPolicy;

/**
 * Finds the callables that hand one of their parameters on as the BEAR application context, so a
 * literal passed to them counts as a context named in source.
 *
 * A parameter qualifies only when it is passed unmodified as the context argument of a context
 * call or of another such callable, and is never written, taken by reference, or variadic. Reads
 * elsewhere in the body are fine. Callables are named by what their call sites can name:
 * `Class::method`, the file that returns the closure, or the single variable that holds it.
 */
final class ContextForwarderIndex
{
    private const DIRECT = '*direct*';

    /** @var list<array{id: string, identities: list<string>, params: array<string, int>, facts: list<array{0: string, 1: int, 2: string}>}> */
    private array $callables = [];
    /** @var array<string, int> callable id => index in $callables */
    private array $positions = [];
    /** @var array<string, int> identity => position of the context argument */
    private array $forwarders = [];
    /** @var array<string, string> identity => callable id */
    private array $callableOf = [];
    /** @var array<string, string> callable id => name of the context parameter */
    private array $contextParameter = [];
    /** @var array<string, int> callable id => call sites seen, resolved or not */
    private array $seen = [];

    public function __construct(private readonly WorkspaceAccessPolicy $policy)
    {
    }

    /**
     * Records the callables of one parsed file.
     *
     * @return list<string> files named by `(require <path>)(...)` calls, for the caller to scan
     */
    public function collectFile(Node $parsed, string $file): array
    {
        $returned = $this->returnedClosure($parsed);
        $writes = [];
        $closures = [];
        $required = [];
        foreach ($parsed->getDescendantNodes() as $node) {
            foreach (self::writesOf($node) as [$anchor, $name]) {
                $key = ContextCallee::variableKeyIn($anchor, $name, $file);
                $writes[$key] = ($writes[$key] ?? 0) + 1;
            }
            if ($node instanceof CallExpression) {
                $callee = ContextCalls::unwrap($node->callableExpression);
                $path = $callee instanceof ScriptInclusionExpression
                    ? ContextCallee::path($callee->expression, $file)
                    : null;
                $inspected = $path === null ? null : $this->policy->inspectExisting($path)->value;
                // Only a PHP file is queued; a required directory would widen the scan to everything in it.
                $absolute = $inspected?->absolute;
                if ($absolute !== null && is_file($absolute) && str_ends_with($absolute, '.php')) {
                    $required[] = $absolute;
                }
            } elseif (
                $node instanceof MethodDeclaration
                || $node instanceof AnonymousFunctionCreationExpression
                || $node instanceof ArrowFunctionCreationExpression
            ) {
                $this->addCallable($node, $file, $returned, $closures);
            }
        }
        // A variable names its closure only when the closure is the one and only thing ever written to it.
        foreach ($closures as $key => $ids) {
            if (($writes[$key] ?? 0) === 1 && count($ids) === 1) {
                $this->callables[$this->positions[$ids[0]]]['identities'][] = $key;
            }
        }

        return $required;
    }

    /** Settles which callables forward, following chains such as closure -> app injector -> Injector. */
    public function resolve(): void
    {
        do {
            $changed = false;
            foreach ($this->callables as $callable) {
                if (isset($this->contextParameter[$callable['id']])) {
                    continue;
                }
                foreach ($callable['facts'] as [$key, $position, $name]) {
                    if ($key !== self::DIRECT && ($this->forwarders[$key] ?? null) !== $position) {
                        continue;
                    }
                    $this->contextParameter[$callable['id']] = $name;
                    foreach ($callable['identities'] as $identity) {
                        $this->forwarders[$identity] = $callable['params'][$name];
                        $this->callableOf[$identity] = $callable['id'];
                    }
                    $changed = true;
                    break;
                }
            }
        } while ($changed);
    }

    public function hasForwarders(): bool
    {
        return $this->forwarders !== [];
    }

    /** Position of the context argument when the callee is a forwarder. */
    public function contextPosition(string $key): ?int
    {
        return $this->forwarders[$key] ?? null;
    }

    public function noteCall(string $key): void
    {
        $id = $this->callableOf[$key] ?? null;
        if ($id !== null) {
            $this->seen[$id] = ($this->seen[$id] ?? 0) + 1;
        }
    }

    /**
     * Which parameter-passing site an unresolved variable argument belongs to, if it is a plain
     * variable inside a method or closure.
     *
     * @return array{0: string, 1: string}|null
     */
    public function enclosingCallable(Node $argument, string $file): ?array
    {
        $variable = ContextCalls::unwrap($argument);
        $scope = $variable instanceof Variable ? ContextCallee::scope($variable) : null;

        return $variable instanceof Variable && $scope !== null
            ? [$file . '#' . $scope->getStartPosition(), $variable->getText()]
            : null;
    }

    /**
     * True when an unresolved variable is the context parameter of a forwarder whose call sites were
     * seen, so the call sites carry the answer. A forwarder nobody calls in a form we can follow
     * keeps its inner site visible.
     *
     * @param array{0: string, 1: string} $enclosing
     */
    public function isAnsweredByCallers(array $enclosing): bool
    {
        return ($this->contextParameter[$enclosing[0]] ?? null) === $enclosing[1]
            && ($this->seen[$enclosing[0]] ?? 0) > 0;
    }

    /** The one closure a file returns from its top level, if it has exactly one return of that kind. */
    private function returnedClosure(Node $parsed): ?Node
    {
        $returns = [];
        foreach ($parsed->getDescendantNodes(static fn (Node $child): bool => !self::isCallable($child)) as $node) {
            if ($node instanceof ReturnStatement) {
                $returns[] = $node;
            }
        }
        if (count($returns) !== 1 || $returns[0]->parent !== $parsed || !$returns[0]->expression instanceof Node) {
            return null;
        }
        $expression = ContextCalls::unwrap($returns[0]->expression);

        return $expression instanceof AnonymousFunctionCreationExpression
            || $expression instanceof ArrowFunctionCreationExpression
            ? $expression
            : null;
    }

    /** @param array<string, list<string>> $closures closures assigned to a variable, by variable key */
    private function addCallable(
        MethodDeclaration|AnonymousFunctionCreationExpression|ArrowFunctionCreationExpression $function,
        string $file,
        ?Node $returned,
        array &$closures,
    ): void {
        $contents = $function->getFileContents();
        $parameters = [];
        foreach (ParserNodes::elements($function->parameters) as $index => $parameter) {
            $name = $parameter instanceof Parameter
                && $parameter->byRefToken === null
                && $parameter->dotDotDotToken === null
                ? ParserNodes::text($parameter->variableName, $contents)
                : null;
            if ($name !== null) {
                $parameters[$name] = $index;
            }
        }
        if ($parameters === []) {
            return;
        }
        [$facts, $written] = $this->scanBody($function, $file, $parameters);
        $parameters = array_diff_key($parameters, $written);
        if ($parameters === []) {
            return;
        }

        $id = $file . '#' . $function->getStartPosition();
        $identities = [];
        if ($function instanceof MethodDeclaration) {
            $class = $function->getFirstAncestor(ClassDeclaration::class);
            $method = ParserNodes::text($function->name, $contents);
            if ($class instanceof ClassDeclaration && $method !== null && $method !== '') {
                $identities[] = ContextCallee::methodKey((string) $class->getNamespacedName(), $method);
            }
        } else {
            if ($returned === $function) {
                $identities[] = ContextCallee::fileKey($file);
            }
            $assignment = $function->parent;
            if (
                $assignment instanceof AssignmentExpression
                && $assignment->rightOperand === $function
                && $assignment->leftOperand instanceof Variable
                && ParserNodes::text($assignment->operator, $contents) === '='
            ) {
                $closures[ContextCallee::variableKey($assignment->leftOperand, $file)][] = $id;
            }
        }

        $this->positions[$id] = count($this->callables);
        $this->callables[] = [
            'id' => $id,
            'identities' => $identities,
            'params' => $parameters,
            'facts' => array_values(array_filter(
                $facts,
                static fn (array $fact): bool => isset($parameters[$fact[2]]),
            )),
        ];
    }

    /**
     * Collects where each parameter is passed on, and which variables the body may write.
     *
     * @param array<string, int> $parameters
     * @return array{0: list<array{0: string, 1: int, 2: string}>, 1: array<string, true>}
     */
    private function scanBody(Node $function, string $file, array $parameters): array
    {
        $contents = $function->getFileContents();
        $facts = [];
        $written = [];
        // A nested closure is its own scope; its by-reference captures still count as writes here.
        foreach ($function->getDescendantNodes(static fn (Node $child): bool => !self::isCallable($child)) as $node) {
            foreach (self::writesOf($node) as [, $name]) {
                $written[$name] = true;
            }
            if ($node instanceof CallExpression) {
                $this->addFacts($node, $file, $contents, $parameters, $facts);
            }
        }

        return [$facts, $written];
    }

    /**
     * The variables one node writes or rebinds, each with a node that sits in the scope that owns it.
     * Passing a variable to a function that takes it by reference cannot be seen from here.
     *
     * @return list<array{0: Node, 1: string}>
     */
    private static function writesOf(Node $node): array
    {
        if ($node instanceof AssignmentExpression) {
            return self::variablesIn($node->leftOperand);
        }
        if ($node instanceof PrefixUpdateExpression || $node instanceof PostfixUpdateExpression) {
            return self::variablesIn($node->operand);
        }
        if ($node instanceof UnsetStatement || $node instanceof ForeachKey || $node instanceof ForeachValue) {
            return self::variablesIn($node);
        }
        if ($node instanceof GlobalDeclaration || $node instanceof FunctionStaticDeclaration) {
            // `global $x;` and `static $x;` rebind the name to a different variable.
            preg_match_all('/\$[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*/', $node->getText(), $names);

            return array_map(static fn (string $name): array => [$node, $name], $names[0]);
        }
        if ($node instanceof CatchClause) {
            $caught = ParserNodes::text($node->variableName, $node->getFileContents());

            return $caught === null ? [] : [[$node, $caught]];
        }
        if ($node instanceof AnonymousFunctionCreationExpression) {
            // `use (&$x)` lets the closure rebind `$x` in the scope that creates it.
            $captured = [];
            foreach (ParserNodes::elements($node->anonymousFunctionUseClause?->useVariableNameList) as $use) {
                if ($use instanceof UseVariableName && $use->byRef !== null) {
                    $captured[] = [$node, '$' . $use->getName()];
                }
            }

            return $captured;
        }

        return [];
    }

    /** @return list<array{0: Node, 1: string}> every variable at or under the node (or none for a token) */
    private static function variablesIn(mixed $node): array
    {
        if (!$node instanceof Node) {
            return [];
        }
        $variables = $node instanceof Variable ? [[$node, $node->getText()]] : [];
        foreach ($node->getDescendantNodes() as $descendant) {
            if ($descendant instanceof Variable) {
                $variables[] = [$descendant, $descendant->getText()];
            }
        }

        return $variables;
    }

    /**
     * @param array<string, int> $parameters
     * @param list<array{0: string, 1: int, 2: string}> $facts
     */
    private function addFacts(
        CallExpression $call,
        string $file,
        string $contents,
        array $parameters,
        array &$facts,
    ): void {
        if (ContextCalls::isContextCall($call, $contents)) {
            $argument = ContextCalls::contextArgument($call, $contents);
            $variable = $argument === null ? null : ContextCalls::unwrap($argument);
            if ($variable instanceof Variable && isset($parameters[$variable->getText()])) {
                $facts[] = [self::DIRECT, 0, $variable->getText()];
            }

            return;
        }
        $key = ContextCallee::key($call, $file, $this->policy);
        if ($key === null) {
            return;
        }
        foreach (ContextCalls::positionalArguments($call, $contents) as $position => $argument) {
            $variable = ContextCalls::unwrap($argument);
            if ($variable instanceof Variable && isset($parameters[$variable->getText()])) {
                $facts[] = [$key, $position, $variable->getText()];
            }
        }
    }

    private static function isCallable(Node $node): bool
    {
        return $node instanceof MethodDeclaration
            || $node instanceof AnonymousFunctionCreationExpression
            || $node instanceof ArrowFunctionCreationExpression;
    }
}
