<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\LanguageServer;

use Amp\Promise;
use Amp\Success;
use Phpactor\Extension\LanguageServerBridge\Converter\PositionConverter;
use Phpactor\LanguageServer\Core\Handler\Handler;
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
use Phpactor\TextDocument\TextDocument;
use Phpactor\TextDocument\TextDocumentBuilder;
use Suzumaze\BearPhpactor\Alps\AlpsDescriptorAtOffset;
use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsDescriptorFacts;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsDescriptorRelationFact;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFacts;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceResolution;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Throwable;

/**
 * Handles BEAR semantic hovers before Phpactor's single Hover handler.
 *
 * Phpactor does not currently expose a Hover provider chain. Intercepting only
 * recognized Resource URI and ALPS descriptor literals keeps
 * its built-in PHP Hover unchanged and avoids depending on extension order.
 */
final class BearHoverMiddleware implements Middleware, Handler
{
    private const INTERNAL_METHOD = 'bear/internal/semanticHover';
    private const MAX_METHODS = 20;
    private const MAX_RELATIONS = 20;
    private const MAX_CANDIDATES = 20;
    private const MAX_FIELD_LENGTH = 500;

    /** @var SemanticResult<WorkspaceContext|null> */
    private SemanticResult $semanticWorkspace;

    public function __construct(
        private Workspace $workspace,
        string $workspaceRoot,
        private StringLiteralAtOffset $stringLiteralAtOffset = new StringLiteralAtOffset(),
        private ResourceFactsQuery $resourceFactsQuery = new ResourceFactsQuery(),
        private AlpsDescriptorAtOffset $alpsDescriptorAtOffset = new AlpsDescriptorAtOffset(),
        private AlpsFactsQuery $alpsFactsQuery = new AlpsFactsQuery(),
    ) {
        $this->semanticWorkspace = WorkspaceContext::fromRoot($workspaceRoot);
    }

    /** @return array<string,string> */
    public function methods(): array
    {
        return [self::INTERNAL_METHOD => 'semanticHover'];
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

            $descriptor = ($this->alpsDescriptorAtOffset)($phpDocument, $offset);
            if ($descriptor !== null) {
                return $this->remap($request, $handler, 'alps', $phpDocument, $source, $descriptor);
            }

            $literal = ($this->stringLiteralAtOffset)($phpDocument, $offset);
            if ($literal === null || ResourceUri::fromString($literal[1]) === null) {
                return $handler->handle($request);
            }
        } catch (Throwable) {
            return $handler->handle($request);
        }

        return $this->remap($request, $handler, 'resource', $phpDocument, $source, $literal);
    }

    /** @return Promise<Hover|null> */
    public function semanticHover(RequestMessage $request): Promise
    {
        try {
            $arguments = $this->semanticArguments($request);
            if ($arguments === null) {
                return new Success(null);
            }

            [$kind, $identifier, $contextPath, $range] = $arguments;
            $hover = match ($kind) {
                'resource' => $this->resourceHover($identifier, $contextPath, $range),
                'alps' => $this->alpsHover($identifier, $contextPath, $range),
                default => null,
            };
        } catch (Throwable) {
            $hover = null;
        }

        return new Success($hover);
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
     * Forward recognized semantics through Phpactor's normal method runner so
     * shutdown, error handling, cancellation bookkeeping, and response wrapping
     * remain active even though the supported Phpactor has no Hover provider chain.
     *
     * @param array{0:int,1:string,2?:int} $literal
     *
     * @return Promise<ResponseMessage|null>
     */
    private function remap(
        RequestMessage $request,
        RequestHandler $handler,
        string $kind,
        TextDocument $document,
        string $source,
        array $literal,
    ): Promise {
        $start = PositionConverter::intByteOffsetToPosition($literal[0], $source);
        $end = PositionConverter::intByteOffsetToPosition(
            $literal[2] ?? $literal[0] + strlen($literal[1]),
            $source,
        );

        return $handler->handle(new RequestMessage($request->id, self::INTERNAL_METHOD, [
            'kind' => $kind,
            'identifier' => $literal[1],
            'contextPath' => $this->contextPath($document),
            'range' => [
                'start' => ['line' => $start->line, 'character' => $start->character],
                'end' => ['line' => $end->line, 'character' => $end->character],
            ],
        ]));
    }

    /**
     * @return array{string,string,string,Range}|null
     */
    private function semanticArguments(RequestMessage $request): ?array
    {
        $params = $request->params;
        if (!is_array($params)) {
            return null;
        }
        $kind = $params['kind'] ?? null;
        $identifier = $params['identifier'] ?? null;
        $contextPath = $params['contextPath'] ?? null;
        $range = $params['range'] ?? null;
        if (
            !is_string($kind)
            || !is_string($identifier)
            || !is_string($contextPath)
            || !is_array($range)
        ) {
            return null;
        }

        $start = $this->position($range['start'] ?? null);
        $end = $this->position($range['end'] ?? null);
        if ($start === null || $end === null) {
            return null;
        }

        return [$kind, $identifier, $contextPath, new Range($start, $end)];
    }

    private function position(mixed $value): ?Position
    {
        if (!is_array($value)) {
            return null;
        }
        $line = $value['line'] ?? null;
        $character = $value['character'] ?? null;
        if (!is_int($line) || $line < 0 || !is_int($character) || $character < 0) {
            return null;
        }

        return new Position($line, $character);
    }

    /**
     * A syntactically valid Resource URI belongs to BEAR semantics. An unresolved
     * or unsafe target intentionally produces an empty Hover instead of falling
     * through to Phpactor's generic string Hover.
     */
    private function resourceHover(string $uri, string $contextPath, Range $range): ?Hover
    {
        if ($this->semanticWorkspace->value === null) {
            return null;
        }

        $facts = $this->resourceFactsQuery->describeInWorkspace(
            $this->semanticWorkspace->value,
            $uri,
            $contextPath,
        );
        $markdown = $this->markdown($facts, $uri);
        if ($markdown === null) {
            return null;
        }

        return new Hover(new MarkupContent('markdown', $markdown), $range);
    }

    private function alpsHover(string $descriptorId, string $contextPath, Range $range): ?Hover
    {
        if ($this->semanticWorkspace->value === null) {
            return null;
        }

        $facts = $this->alpsFactsQuery->describeInWorkspace(
            $this->semanticWorkspace->value,
            $descriptorId,
            $contextPath,
        );
        $markdown = $this->alpsMarkdown($facts, $descriptorId);
        if ($markdown === null) {
            return null;
        }

        return new Hover(new MarkupContent('markdown', $markdown), $range);
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

    /**
     * @param SemanticResult<AlpsDescriptorFacts|null> $result
     */
    private function alpsMarkdown(SemanticResult $result, string $descriptorId): ?string
    {
        if ($result->status === SemanticStatus::Ok && $result->value !== null) {
            return $this->alpsFactsMarkdown($result->value);
        }
        if ($result->status !== SemanticStatus::Ambiguous) {
            return null;
        }

        $lines = [
            sprintf('**ALPS descriptor** %s', $this->code($this->boundedField($descriptorId))),
            '',
            'Ambiguous candidates:',
        ];
        foreach (array_slice($result->candidates, 0, self::MAX_CANDIDATES) as $candidate) {
            $resolution = $candidate->descriptor->resolution;
            $lines[] = sprintf(
                '- %s at byte %d',
                $this->code($this->relativeFile($resolution->profileFile)),
                $resolution->offset,
            );
        }
        if (count($result->candidates) > self::MAX_CANDIDATES) {
            $lines[] = sprintf('- … %d more', count($result->candidates) - self::MAX_CANDIDATES);
        }

        return implode("\n", $lines);
    }

    private function alpsFactsMarkdown(AlpsDescriptorFacts $facts): string
    {
        $descriptor = $facts->descriptor;
        $lines = [
            sprintf(
                '**ALPS descriptor** %s',
                $this->code($this->boundedField($descriptor->resolution->descriptorId)),
            ),
            '',
            sprintf('Profile: %s', $this->code($this->relativeFile($descriptor->resolution->profileFile))),
        ];

        foreach (
            [
            'Type' => $descriptor->type,
            'Title' => $descriptor->title,
            'Name' => $descriptor->name,
            'rel' => $descriptor->rel,
            'rt' => $descriptor->rt,
            'href' => $descriptor->href,
            'def' => $descriptor->def,
            'tag' => $descriptor->tag,
            'doc' => $descriptor->doc,
            ] as $label => $value
        ) {
            if ($value !== null) {
                $lines[] = sprintf('%s: %s', $label, $this->code($this->boundedField($value)));
            }
        }

        $this->appendRelations($lines, 'Outgoing relations', $facts->outgoingRelations, true);
        $this->appendRelations($lines, 'Incoming relations', $facts->incomingRelations, false);

        return implode("\n", $lines);
    }

    /**
     * @param list<string>                     $lines
     * @param list<AlpsDescriptorRelationFact> $relations
     */
    private function appendRelations(array &$lines, string $heading, array $relations, bool $outgoing): void
    {
        if ($relations === []) {
            return;
        }

        $lines[] = '';
        $lines[] = sprintf('**%s**', $heading);
        foreach (array_slice($relations, 0, self::MAX_RELATIONS) as $relation) {
            $otherId = $outgoing ? $relation->targetId : ($relation->sourceId ?? '(anonymous)');
            $lines[] = sprintf(
                '- %s %s %s (%s)',
                $this->code($relation->kind),
                $outgoing ? '→' : '←',
                $this->code($this->boundedField($otherId)),
                $relation->targetStatus->value,
            );
        }
        if (count($relations) > self::MAX_RELATIONS) {
            $lines[] = sprintf('- … %d more', count($relations) - self::MAX_RELATIONS);
        }
    }

    private function contextPath(TextDocument $document): ?string
    {
        if ($this->semanticWorkspace->value === null || $document->uri() === null) {
            return null;
        }

        return $this->semanticWorkspace->value->accessPolicy()
            ->inspectExisting($document->uri()->path())->value->relative ?? null;
    }

    private function relativePath(ResourceResolution $resource): string
    {
        return $this->relativeFile($resource->file);
    }

    private function relativeFile(string $file): string
    {
        if ($this->semanticWorkspace->value === null) {
            return $file;
        }

        return $this->semanticWorkspace->value->accessPolicy()
            ->inspectExisting($file)->value->relative ?? basename($file);
    }

    private function code(string $value): string
    {
        preg_match_all('/`+/', $value, $matches);
        $maxRun = 0;
        foreach ($matches[0] as $run) {
            $maxRun = max($maxRun, strlen($run));
        }
        $delimiter = str_repeat('`', $maxRun + 1);
        $padding = '';
        if (
            str_starts_with($value, '`')
            || str_ends_with($value, '`')
            || (
                str_starts_with($value, ' ')
                && str_ends_with($value, ' ')
                && trim($value, ' ') !== ''
            )
        ) {
            $padding = ' ';
        }

        return $delimiter . $padding . $value . $padding . $delimiter;
    }

    private function singleLine(string $value): string
    {
        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    private function boundedField(string $value): string
    {
        $value = $this->singleLine($value);
        if (preg_match('/\A.{0,' . self::MAX_FIELD_LENGTH . '}\z/us', $value) === 1) {
            return $value;
        }

        if (preg_match('/\A(.{0,' . (self::MAX_FIELD_LENGTH - 1) . '})/us', $value, $matches) !== 1) {
            return '…';
        }

        return $matches[1] . '…';
    }
}
