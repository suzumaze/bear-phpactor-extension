<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\LanguageServer;

use Amp\Promise;
use Amp\Success;
use Phpactor\Extension\LanguageServerBridge\Converter\PositionConverter;
use Phpactor\LanguageServer\Core\Middleware\Middleware;
use Phpactor\LanguageServer\Core\Middleware\RequestHandler;
use Phpactor\LanguageServer\Core\Rpc\Message;
use Phpactor\LanguageServer\Core\Rpc\RequestMessage;
use Phpactor\LanguageServer\Core\Rpc\ResponseMessage;
use Phpactor\LanguageServer\Core\Workspace\Workspace;
use Phpactor\LanguageServerProtocol\Hover;
use Phpactor\LanguageServerProtocol\MarkupContent;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocument;
use Phpactor\TextDocument\TextDocumentBuilder;
use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFacts;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceResolution;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Throwable;

/**
 * Handles BEAR Resource URI hovers before Phpactor's single Hover handler.
 *
 * Phpactor does not currently expose a Hover provider chain. Intercepting only
 * recognized Resource URI literals keeps its built-in PHP Hover unchanged and
 * avoids depending on extension registration order.
 */
final class BearHoverMiddleware implements Middleware
{
    private const MAX_METHODS = 20;

    /** @var SemanticResult<WorkspaceContext|null> */
    private SemanticResult $semanticWorkspace;

    public function __construct(
        private Workspace $workspace,
        string $workspaceRoot,
        private StringLiteralAtOffset $stringLiteralAtOffset = new StringLiteralAtOffset(),
        private ResourceFactsQuery $resourceFactsQuery = new ResourceFactsQuery(),
    ) {
        $this->semanticWorkspace = WorkspaceContext::fromRoot($workspaceRoot);
    }

    /** @return Promise<ResponseMessage|null> */
    public function process(Message $request, RequestHandler $handler): Promise
    {
        if (!$request instanceof RequestMessage || $request->method !== 'textDocument/hover') {
            return $handler->handle($request);
        }

        $arguments = $this->arguments($request);
        if ($arguments === null) {
            return $handler->handle($request);
        }

        [$textDocument, $position] = $arguments;
        try {
            $document = $this->workspace->get($textDocument->uri);
            if ($document->languageId !== 'php') {
                return $handler->handle($request);
            }

            $source = $document->text;
            $offset = PositionConverter::positionToByteOffset($position, $source)->toInt();
            $phpDocument = TextDocumentBuilder::create($source)
                ->uri($document->uri)
                ->language('php')
                ->build();
            $literal = ($this->stringLiteralAtOffset)($phpDocument, $offset);
            if ($literal === null || ResourceUri::fromString($literal[1]) === null) {
                return $handler->handle($request);
            }
        } catch (Throwable) {
            return $handler->handle($request);
        }

        // A syntactically valid Resource URI belongs to BEAR semantics. An
        // unresolved or unsafe target intentionally produces an empty Hover
        // instead of falling through to Phpactor's generic string Hover.
        try {
            $hover = $this->resourceHover($phpDocument, $source, $literal);
        } catch (Throwable) {
            $hover = null;
        }

        return new Success(new ResponseMessage($request->id, $hover));
    }

    /**
     * @return array{TextDocumentIdentifier,Position}|null
     */
    private function arguments(RequestMessage $request): ?array
    {
        $textDocument = $request->params['textDocument'] ?? null;
        $position = $request->params['position'] ?? null;
        if (!is_array($textDocument) || !is_array($position)) {
            return null;
        }

        $uri = $textDocument['uri'] ?? null;
        $line = $position['line'] ?? null;
        $character = $position['character'] ?? null;
        if (
            !is_string($uri)
            || $uri === ''
            || !is_int($line)
            || $line < 0
            || !is_int($character)
            || $character < 0
        ) {
            return null;
        }

        return [new TextDocumentIdentifier($uri), new Position($line, $character)];
    }

    /**
     * @param array{int,string} $literal
     */
    private function resourceHover(TextDocument $phpDocument, string $source, array $literal): ?Hover
    {
        if ($this->semanticWorkspace->value === null || $phpDocument->uri() === null) {
            return null;
        }
        $context = $this->semanticWorkspace->value->accessPolicy()->inspectExisting(
            $phpDocument->uri()->path(),
        );
        if ($context->value === null) {
            return null;
        }

        $facts = $this->resourceFactsQuery->describeInWorkspace(
            $this->semanticWorkspace->value,
            $literal[1],
            $context->value->relative,
        );
        $markdown = $this->markdown($facts, $literal[1]);
        if ($markdown === null) {
            return null;
        }

        return new Hover(
            new MarkupContent('markdown', $markdown),
            new Range(
                PositionConverter::byteOffsetToPosition(ByteOffset::fromInt($literal[0]), $source),
                PositionConverter::byteOffsetToPosition(
                    ByteOffset::fromInt($literal[0] + strlen($literal[1])),
                    $source,
                ),
            ),
        );
    }

    /**
     * @param SemanticResult<ResourceFacts|null> $result
     */
    private function markdown(SemanticResult $result, string $uri): ?string
    {
        if ($result->status === SemanticStatus::Ok && $result->value !== null) {
            return $this->factsMarkdown($result->value);
        }
        if ($result->status !== SemanticStatus::Ambiguous) {
            return null;
        }

        $lines = [sprintf('**BEAR Resource** %s', $this->code($uri)), '', 'Ambiguous candidates:'];
        foreach ($result->candidates as $candidate) {
            $lines[] = sprintf(
                '- %s — %s',
                $this->code($candidate->resource->fqn),
                $this->code($this->relativePath($candidate->resource)),
            );
        }

        return implode("\n", $lines);
    }

    private function factsMarkdown(ResourceFacts $facts): string
    {
        $lines = [
            sprintf('**BEAR Resource** %s', $this->code($facts->resource->uri->uri())),
            '',
            $this->code($facts->resource->fqn),
            '',
            sprintf('Path: %s', $this->code($this->relativePath($facts->resource))),
        ];

        if ($facts->methods !== []) {
            $lines[] = '';
            $lines[] = '**Resource methods**';
            foreach (array_slice($facts->methods, 0, self::MAX_METHODS) as $method) {
                $parameters = array_map(
                    fn ($parameter): string => trim(sprintf(
                        '%s $%s',
                        $this->singleLine($parameter->type ?? ''),
                        $parameter->name,
                    )),
                    $method->parameters,
                );
                $lines[] = '- ' . $this->code(sprintf('%s(%s)', $method->name, implode(', ', $parameters)));
            }
            if (count($facts->methods) > self::MAX_METHODS) {
                $lines[] = sprintf('- … %d more', count($facts->methods) - self::MAX_METHODS);
            }
        }

        $lines[] = '';
        $lines[] = sprintf('Outgoing Link / Embed relations: %d', count($facts->outgoingRelations));

        return implode("\n", $lines);
    }

    private function relativePath(ResourceResolution $resource): string
    {
        if ($this->semanticWorkspace->value === null) {
            return $resource->file;
        }

        return $this->semanticWorkspace->value->accessPolicy()
            ->inspectExisting($resource->file)->value->relative ?? basename($resource->file);
    }

    private function code(string $value): string
    {
        return '`' . str_replace('`', '\\`', $value) . '`';
    }

    private function singleLine(string $value): string
    {
        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }
}
