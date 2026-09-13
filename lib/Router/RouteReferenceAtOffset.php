<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Router;

use Microsoft\PhpParser\Node\DelimitedList\ArgumentExpressionList;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Parser;
use Microsoft\PhpParser\Token;
use Phpactor\TextDocument\TextDocument;
use Phpactor\WorseReflection\Core\Util\NodeUtil;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;

/**
 * Locates a static Aura.Router route name without resolving its Page resource.
 */
final class RouteReferenceAtOffset
{
    private const ROUTE_FILE = 'aura.route.php';
    private const ROUTE_METHOD_NAMES = ['route', 'get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

    public function __construct(
        private StringLiteralAtOffset $stringLiteralAtOffset = new StringLiteralAtOffset(),
        private Parser $parser = new Parser(),
    ) {
    }

    /**
     * @return array{int,string,int}|null Content start, route name, and content end byte offset.
     */
    public function __invoke(TextDocument $document, int $byteOffset): ?array
    {
        if (!$this->isRouteDocument($document)) {
            return null;
        }

        $literal = $this->stringLiteralAtOffset->literal($document, $byteOffset);
        if ($literal === null) {
            return null;
        }

        return $this->referenceFromLiteral($document, $literal, $byteOffset);
    }

    /** @return list<array{int,string,int}> */
    public function references(TextDocument $document): array
    {
        if (!$this->isRouteDocument($document)) {
            return [];
        }

        $references = [];
        $root = $this->parser->parseSourceFile($document->__toString(), $document->uri()?->__toString());
        foreach ($root->getDescendantNodes() as $node) {
            if (!$node instanceof StringLiteral) {
                continue;
            }
            $reference = $this->referenceFromLiteral($document, $node, $node->getStartPosition() + 1);
            if ($reference !== null) {
                $references[] = $reference;
            }
        }

        return $references;
    }

    /** @return array{int,string,int}|null */
    private function referenceFromLiteral(
        TextDocument $document,
        StringLiteral $literal,
        int $byteOffset,
    ): ?array {
        $text = $document->__toString();
        $opening = substr($text, $literal->getStartPosition(), 1);
        if ($opening !== "'" && $opening !== '"') {
            return null;
        }

        $contentStart = $literal->getStartPosition() + 1;
        $contentEnd = $literal->getEndPosition() - 1;
        if ($byteOffset < $contentStart || $byteOffset >= $contentEnd) {
            return null;
        }

        $routeName = $literal->getStringContentsText();
        if (!str_starts_with($routeName, '/')) {
            return null;
        }

        $argument = $literal->getParent();
        if (!$argument instanceof ArgumentExpression || $argument->expression !== $literal) {
            return null;
        }

        $argumentList = $argument->getParent();
        if (!$argumentList instanceof ArgumentExpressionList) {
            return null;
        }

        if ($argument->name instanceof Token) {
            if ($argument->name->getText($text) !== 'name') {
                return null;
            }
        } elseif (!isset($argumentList->children[0]) || $argumentList->children[0] !== $argument) {
            return null;
        }

        $call = $argumentList->getParent();
        if (!$call instanceof CallExpression || !$this->isRouteCall($call)) {
            return null;
        }

        return [$contentStart, $routeName, $contentEnd];
    }

    private function isRouteDocument(TextDocument $document): bool
    {
        $uri = $document->uri();

        return $uri !== null && basename($uri->path()) === self::ROUTE_FILE;
    }

    private function isRouteCall(CallExpression $call): bool
    {
        $callable = $call->callableExpression;
        if ($callable instanceof MemberAccessExpression) {
            return in_array(
                NodeUtil::nameFromTokenOrNode($call, $callable->memberName),
                self::ROUTE_METHOD_NAMES,
                true,
            );
        }
        if ($callable instanceof QualifiedName) {
            return in_array(ltrim($callable->__toString(), '\\'), self::ROUTE_METHOD_NAMES, true);
        }

        return false;
    }
}
