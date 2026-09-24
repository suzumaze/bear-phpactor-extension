<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\LanguageServer;

use Amp\CancellationToken;
use Amp\Promise;
use Phpactor\Extension\LanguageServerBridge\Converter\PositionConverter;
use Phpactor\LanguageServer\Core\Diagnostics\DiagnosticsProvider;
use Phpactor\LanguageServerProtocol\Diagnostic;
use Phpactor\LanguageServerProtocol\DiagnosticSeverity;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\TextDocument\TextDocumentUri;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectDiagnostic;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectDiagnosticsQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Throwable;

use function Amp\call;

/**
 * Publishes bounded BEAR reference diagnostics for the current editor buffer.
 */
final class BearDiagnosticsProvider implements DiagnosticsProvider
{
    private ?WorkspaceContext $workspace;

    public function __construct(
        string $workspaceRoot,
        private ProjectDiagnosticsQuery $query,
    ) {
        $this->workspace = WorkspaceContext::fromRoot($workspaceRoot)->value;
    }

    /** @return Promise<array<Diagnostic>> */
    public function provideDiagnostics(TextDocumentItem $textDocument, CancellationToken $cancel): Promise
    {
        return call(function () use ($textDocument, $cancel): array {
            $cancel->throwIfRequested();
            if ($this->workspace === null) {
                return [];
            }

            try {
                $uri = TextDocumentUri::fromString($textDocument->uri);
            } catch (Throwable) {
                return [];
            }
            if ($uri->scheme() !== TextDocumentUri::SCHEME_FILE) {
                return [];
            }

            $result = $this->query->diagnoseDocumentInWorkspace(
                $this->workspace,
                $uri->path(),
                $textDocument->text,
            );
            if (!is_array($result->value)) {
                return [];
            }

            $diagnostics = [];
            foreach ($result->value as $item) {
                $cancel->throwIfRequested();
                $diagnostic = $this->diagnostic($item, $textDocument->text);
                if ($diagnostic !== null) {
                    $diagnostics[] = $diagnostic;
                }
            }

            return $diagnostics;
        });
    }

    public function name(): string
    {
        return 'bear';
    }

    private function diagnostic(ProjectDiagnostic $item, string $text): ?Diagnostic
    {
        if ($item->byteStart === null || $item->byteEnd === null) {
            return null;
        }
        $start = max(0, min(strlen($text), $item->byteStart));
        $end = max($start, min(strlen($text), $item->byteEnd));
        try {
            $range = new Range(
                PositionConverter::intByteOffsetToPosition($start, $text),
                PositionConverter::intByteOffsetToPosition($end, $text),
            );
        } catch (Throwable) {
            return null;
        }

        return new Diagnostic(
            $range,
            $this->message($item),
            severity: DiagnosticSeverity::WARNING,
            code: $item->code,
            source: 'bear',
            data: [
                'status' => $item->status->value,
                'subject' => $item->subject,
                'details' => $item->details,
            ],
        );
    }

    private function message(ProjectDiagnostic $item): string
    {
        $kind = match (true) {
            str_starts_with($item->code, 'resource_reference_') => 'Resource reference',
            str_starts_with($item->code, 'sql_reference_') => 'SQL reference',
            str_starts_with($item->code, 'schema_reference_') => 'JSON Schema reference',
            str_starts_with($item->code, 'alps_descriptor_') => 'ALPS descriptor',
            str_starts_with($item->code, 'template_reference_') => 'Template reference',
            str_starts_with($item->code, 'route_resource_') => 'Route Resource',
            default => 'BEAR reference',
        };
        $state = match ($item->status) {
            SemanticStatus::NotFound => 'was not found',
            SemanticStatus::Ambiguous => 'is ambiguous',
            SemanticStatus::InvalidInput => 'is invalid',
            SemanticStatus::ParseError => 'is malformed',
            default => 'could not be resolved',
        };

        return sprintf('%s %s: %s', $kind, $state, $item->subject);
    }
}
