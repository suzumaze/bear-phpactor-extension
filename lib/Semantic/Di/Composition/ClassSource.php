<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di\Composition;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\ClassConstDeclaration;
use Microsoft\PhpParser\Node\ClassMembersNode;
use Microsoft\PhpParser\Node\ConstElement;
use Microsoft\PhpParser\Node\EnumMembers;
use Microsoft\PhpParser\Node\Expression\Variable;
use Microsoft\PhpParser\Node\InterfaceMembers;
use Microsoft\PhpParser\Node\MethodDeclaration;
use Microsoft\PhpParser\Node\PropertyDeclaration;
use Microsoft\PhpParser\Node\PropertyElement;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Node\Statement\EnumDeclaration;
use Microsoft\PhpParser\Node\Statement\InterfaceDeclaration;
use Microsoft\PhpParser\Node\Statement\TraitDeclaration;
use Microsoft\PhpParser\Node\TraitMembers;
use Microsoft\PhpParser\Node\TraitUseClause;
use Microsoft\PhpParser\Token;

/**
 * One parsed class-like declaration; members are read from syntax only.
 */
final readonly class ClassSource
{
    /** @param list<string> $interfaces */
    public function __construct(
        public string $name,
        public ClassDeclaration|InterfaceDeclaration|TraitDeclaration|EnumDeclaration $node,
        public string $contents,
        public string $path,
        public ?string $parent,
        public array $interfaces,
    ) {
    }

    public function isInterface(): bool
    {
        return $this->node instanceof InterfaceDeclaration;
    }

    public function isClass(): bool
    {
        return $this->node instanceof ClassDeclaration;
    }

    public function isAbstract(): bool
    {
        return $this->node instanceof ClassDeclaration && $this->hasClassModifier('abstract');
    }

    public function isFinal(): bool
    {
        return $this->node instanceof ClassDeclaration && $this->hasClassModifier('final');
    }

    public function method(string $name): ?MethodDeclaration
    {
        foreach ($this->members() as $member) {
            if ($member instanceof MethodDeclaration && strtolower($member->getName()) === strtolower($name)) {
                return $member;
            }
        }

        return null;
    }

    /** @return list<MethodDeclaration> in declaration order */
    public function methods(): array
    {
        $methods = [];
        foreach ($this->members() as $member) {
            if ($member instanceof MethodDeclaration) {
                $methods[] = $member;
            }
        }

        return $methods;
    }

    public function constant(string $name): ?Node
    {
        foreach ($this->members() as $member) {
            if (!$member instanceof ClassConstDeclaration) {
                continue;
            }
            foreach (ParserNodes::elements($member->constElements) as $element) {
                if ($element instanceof ConstElement && $element->getName() === $name) {
                    return $element->assignment;
                }
            }
        }

        return null;
    }

    /** @return array<string, Node|null> property name => default expression */
    public function propertyDefaults(): array
    {
        $defaults = [];
        foreach ($this->members() as $member) {
            if (!$member instanceof PropertyDeclaration) {
                continue;
            }
            foreach (ParserNodes::elements($member->propertyElements) as $element) {
                if ($element instanceof PropertyElement && $element->variable instanceof Variable) {
                    $defaults[(string) $element->variable->getName()] = $element->initializer;
                }
            }
        }

        return $defaults;
    }

    /** @return list<string> */
    public function traits(): array
    {
        $traits = [];
        foreach ($this->members() as $member) {
            if (!$member instanceof TraitUseClause) {
                continue;
            }
            foreach (ParserNodes::elements($member->traitNameList) as $name) {
                if ($name instanceof QualifiedName && ($resolved = $name->getResolvedName()) !== null) {
                    $traits[] = ltrim((string) $resolved, '\\');
                }
            }
        }

        return $traits;
    }

    /** @return iterable<Node> */
    private function members(): iterable
    {
        foreach ($this->node->getChildNodes() as $child) {
            if (
                $child instanceof ClassMembersNode
                || $child instanceof InterfaceMembers
                || $child instanceof TraitMembers
                || $child instanceof EnumMembers
            ) {
                yield from $child->getChildNodes();
            }
        }
    }

    private function hasClassModifier(string $modifier): bool
    {
        $node = $this->node;
        assert($node instanceof ClassDeclaration);
        foreach ([$node->abstractOrFinalModifier, ...ParserNodes::elements($node->modifiers)] as $token) {
            if ($token instanceof Token && strtolower($token->getText($this->contents)) === $modifier) {
                return true;
            }
        }

        return false;
    }
}
