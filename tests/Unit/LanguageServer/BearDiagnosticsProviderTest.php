<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\LanguageServer;

use Amp\NullCancellationToken;
use Phpactor\LanguageServerProtocol\DiagnosticSeverity;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\TextDocument\TextDocumentUri;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\BearDiagnosticsProvider;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectDiagnosticsQuery;

use function Amp\Promise\wait;

final class BearDiagnosticsProviderTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/bear-document-diagnostics-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->workspace . '/src', 0777, true));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/composer.json',
            '{"autoload":{"psr-4":{"Acme\\\\App\\\\":"src/"}}}',
        ));
        self::assertNotFalse(file_put_contents($this->workspace . '/src/Client.php', '<?php'));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testPublishesWarningFromCurrentBufferWithStableCodeAndData(): void
    {
        $source = "<?php\n\$label = '😀';\n\$uri = 'app://self/missing';\n";
        $diagnostics = wait($this->provider()->provideDiagnostics(
            new TextDocumentItem(
                (string) TextDocumentUri::fromString($this->workspace . '/src/Client.php'),
                'php',
                2,
                $source,
            ),
            new NullCancellationToken(),
        ));

        self::assertCount(1, $diagnostics);
        self::assertSame(DiagnosticSeverity::WARNING, $diagnostics[0]->severity);
        self::assertSame('resource_reference_not_found', $diagnostics[0]->code);
        self::assertSame('bear', $diagnostics[0]->source);
        self::assertSame('Resource reference was not found: app://self/missing', $diagnostics[0]->message);
        self::assertSame('app://self/missing', $diagnostics[0]->data['subject']);
        self::assertSame(2, $diagnostics[0]->range->start->line);
        self::assertGreaterThan($diagnostics[0]->range->start->character, $diagnostics[0]->range->end->character);
    }

    public function testIgnoresNonFileDocuments(): void
    {
        $diagnostics = wait($this->provider()->provideDiagnostics(
            new TextDocumentItem('untitled:Scratch', 'php', 1, "<?php 'app://self/missing';"),
            new NullCancellationToken(),
        ));

        self::assertSame([], $diagnostics);
    }

    private function provider(): BearDiagnosticsProvider
    {
        return new BearDiagnosticsProvider($this->workspace, new ProjectDiagnosticsQuery());
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
        }
        rmdir($path);
    }
}
