<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\Expression\Variable;
use Microsoft\PhpParser\Node\MethodDeclaration;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Token;
use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;

/**
 * Extracts direct Resource client calls whose target URI is a string literal.
 *
 * This deliberately does not treat every URI-looking string as a call. Constants,
 * prefix checks, exception messages, fixtures, and assertions are not execution
 * evidence and therefore stay outside project diagnostics and outgoing calls.
 */
final class ResourceCallScanner
{
    /** @var array<string,string|null> */
    private const TARGET_METHODS = [
        'get' => 'onGet',
        'post' => 'onPost',
        'put' => 'onPut',
        'patch' => 'onPatch',
        'delete' => 'onDelete',
        'head' => 'onHead',
        'options' => 'onOptions',
        'uri' => null,
    ];

    /** @return list<ResourceCallFact> */
    public function scan(Node $root, string $source, string $file): array
    {
        $facts = [];
        foreach ($root->getDescendantNodes() as $node) {
            if (!$node instanceof CallExpression) {
                continue;
            }
            $callable = $node->callableExpression;
            if (!$callable instanceof MemberAccessExpression || !$this->isResourceReceiver($callable, $source)) {
                continue;
            }
            $call = strtolower($callable->memberName->getText($source));
            if (!array_key_exists($call, self::TARGET_METHODS)) {
                continue;
            }
            $literal = $this->uriLiteral($node, $source);
            if ($literal === null) {
                continue;
            }
            $uri = ResourceUri::fromString($literal->getStringContentsText());
            if ($uri === null) {
                continue;
            }

            $facts[] = new ResourceCallFact(
                $call,
                $uri,
                $this->sourceMethod($node),
                self::TARGET_METHODS[$call],
                $file,
                $literal->getStartPosition() + 1,
                $literal->getEndPosition() - 1,
            );
        }

        usort($facts, static fn (ResourceCallFact $left, ResourceCallFact $right): int => [
            $left->sourceFile,
            $left->contentStart,
            $left->contentEnd,
            $left->call,
            $left->targetUri->uri(),
        ] <=> [
            $right->sourceFile,
            $right->contentStart,
            $right->contentEnd,
            $right->call,
            $right->targetUri->uri(),
        ]);

        return $facts;
    }

    private function isResourceReceiver(MemberAccessExpression $callable, string $source): bool
    {
        $receiver = $callable->dereferencableExpression;
        if ($receiver instanceof Variable) {
            return $receiver->getName() === 'resource';
        }
        if (!$receiver instanceof MemberAccessExpression) {
            return false;
        }

        return $receiver->memberName->getText($source) === 'resource'
            && $receiver->dereferencableExpression instanceof Variable
            && $receiver->dereferencableExpression->getName() === 'this';
    }

    private function uriLiteral(CallExpression $call, string $source): ?StringLiteral
    {
        if ($call->argumentExpressionList === null) {
            return null;
        }
        $position = 0;
        foreach ($call->argumentExpressionList->getElements() as $argument) {
            if (!$argument instanceof ArgumentExpression || $argument->dotDotDotToken instanceof Token) {
                ++$position;
                continue;
            }
            $isUri = $argument->name instanceof Token
                ? $argument->name->getText($source) === 'uri'
                : $position === 0;
            if ($isUri && $argument->expression instanceof StringLiteral) {
                $opening = substr($source, $argument->expression->getStartPosition(), 1);

                return $opening === "'" || $opening === '"' ? $argument->expression : null;
            }
            ++$position;
        }

        return null;
    }

    private function sourceMethod(CallExpression $call): ?string
    {
        for ($parent = $call->getParent(); $parent !== null; $parent = $parent->getParent()) {
            if ($parent instanceof MethodDeclaration) {
                return $parent->getName();
            }
        }

        return null;
    }
}
