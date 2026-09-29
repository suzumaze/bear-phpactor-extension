<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Aop;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\MethodDeclaration;
use Microsoft\PhpParser\Node\Parameter;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSource;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSourceIndex;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ParserNodes;
use Suzumaze\BearPhpactor\Util\PhpAttributeName;

/** Three-valued PHP attribute matcher; absence of readable source is never a negative match. */
final readonly class SourceMatcher
{
    public function __construct(private ClassSourceIndex $classes)
    {
    }

    /** @return list<string> Names only: attribute argument values are not exposed. */
    public static function attributes(Node $node): array
    {
        $names = [];
        foreach (ParserNodes::elements($node->attributes ?? null) as $group) {
            foreach (ParserNodes::elements($group->attributes ?? null) as $attribute) {
                if ($attribute instanceof Attribute && ($name = PhpAttributeName::resolve($attribute)) !== null) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /** @param list<string> $seen */
    public function isA(string $class, string $parent, array $seen = []): ?bool
    {
        if (strcasecmp(ltrim($class, '\\'), ltrim($parent, '\\')) === 0) {
            return true;
        }
        if (in_array(strtolower($class), $seen, true) || count($seen) > 64) {
            return null;
        }
        $source = $this->classes->find($class);
        if ($source === null) {
            $internal = $this->classes->internal($class);

            if ($internal === null) {
                return null;
            }

            $internalNames = [
                $internal->getName(),
                ...$internal->getInterfaceNames(),
            ];
            for ($current = $internal->getParentClass(); $current !== false; $current = $current->getParentClass()) {
                $internalNames[] = $current->getName();
            }

            foreach ($internalNames as $internalName) {
                if (strcasecmp($internalName, ltrim($parent, '\\')) === 0) {
                    return true;
                }
            }

            // The already-loaded internal hierarchy is complete; inspecting names does not autoload the target.
            return false;
        }
        $seen[] = strtolower($class);
        $unknown = false;
        foreach ([...$source->interfaces, ...($source->parent === null ? [] : [$source->parent])] as $base) {
            $match = $this->isA($base, $parent, $seen);
            if ($match === true) {
                return true;
            }
            $unknown = $unknown || $match === null;
        }

        return $unknown ? null : false;
    }

    public function matches(?MatcherValue $matcher, ClassSource $class, ?MethodDeclaration $method = null): ?bool
    {
        if ($matcher === null) {
            return null;
        }
        switch ($matcher->kind) {
            case 'any':
                return true;
            case 'startswith':
                return str_starts_with($method?->getName() ?? $class->name, (string) $matcher->value);
            case 'subclassesof':
                if ($this->classes->find((string) $matcher->value)?->isClass() !== true) {
                    return null;
                }

                return $method === null ? $this->isA($class->name, (string) $matcher->value) : null;
            case 'annotatedwith':
                if ($this->classes->find((string) $matcher->value)?->isClass() !== true) {
                    return null;
                }
                $unknown = false;
                foreach (self::attributes($method ?? $class->node) as $attribute) {
                    $match = $this->isA($attribute, (string) $matcher->value);
                    if ($match === true) {
                        return true;
                    }
                    $unknown = $unknown || $match === null;
                }

                return $unknown ? null : false;
            case 'assistedinject':
                if ($method === null) {
                    return null;
                }
                $unknown = false;
                foreach (ParserNodes::elements($method->parameters) as $parameter) {
                    if (!$parameter instanceof Parameter) {
                        continue;
                    }
                    foreach (self::attributes($parameter) as $attribute) {
                        if (strcasecmp(ltrim($attribute, '\\'), 'Ray\\Di\\Di\\Assisted') === 0) {
                            return true;
                        }
                        $match = $this->isA($attribute, 'Ray\\Di\\Di\\InjectInterface');
                        if ($match === true) {
                            return true;
                        }
                        $unknown = $unknown || $match === null;
                    }
                }

                return $unknown ? null : false;
            case 'logicalnot':
                $match = $this->matches($matcher->operands[0], $class, $method);

                return $match === null ? null : !$match;
            case 'logicaland':
            case 'logicalor':
                $unknown = false;
                $shortCircuit = $matcher->kind === 'logicalor';
                foreach ($matcher->operands as $operand) {
                    $match = $this->matches($operand, $class, $method);
                    if ($match === $shortCircuit) {
                        return $shortCircuit;
                    }
                    $unknown = $unknown || $match === null;
                }

                return $unknown ? null : !$shortCircuit;
        }

        return null;
    }

    /**
     * Only public source methods. Trait adaptations require a separate resolver.
     * @param list<string> $seen
     * @return array{methods: array<string, array{ClassSource, MethodDeclaration}>, complete: bool}
     */
    public function methods(ClassSource $class, array $seen = []): array
    {
        if (in_array(strtolower($class->name), $seen, true) || count($seen) > 64) {
            return ['methods' => [], 'complete' => false];
        }
        $methods = [];
        $complete = $class->traits() === [];
        $seen[] = strtolower($class->name);
        if ($class->parent !== null) {
            $parent = $this->classes->find($class->parent);
            $inherited = $parent === null ? ['methods' => [], 'complete' => false] : $this->methods($parent, $seen);
            $methods = $inherited['methods'];
            $complete = $complete && $inherited['complete'];
        }
        foreach ($class->methods() as $method) {
            $key = strtolower($method->getName());
            unset($methods[$key]);
            $modifiers = array_map(
                static fn ($token): string => strtolower($token->getText($class->contents)),
                ParserNodes::elements($method->modifiers),
            );
            if ($key !== '__construct' && !array_intersect(['private', 'protected'], $modifiers)) {
                $methods[$key] = [$class, $method];
            }
        }
        ksort($methods);

        return ['methods' => $methods, 'complete' => $complete];
    }
}
