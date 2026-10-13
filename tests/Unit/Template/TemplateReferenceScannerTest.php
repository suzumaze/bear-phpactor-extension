<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;
use Phpactor\TextDocument\TextDocumentBuilder;
use Suzumaze\BearPhpactor\Template\TemplateReferenceScanner;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;

use function Amp\Promise\wait;

final class TemplateReferenceScannerTest extends TestCase
{
    public function testConcatenatedPrefixesAreDynamicAndNotMissingTemplates(): void
    {
        $root = dirname(__DIR__, 2) . '/Fixture/TemplateDynamic';
        $file = $root . '/var/templates/example.html.twig';
        $document = TextDocumentBuilder::create(file_get_contents($file))->uri($file)->language('twig')->build();
        $scanner = new TemplateReferenceScanner();
        self::assertSame(
            ['missing.html.twig', 'missing.html.twig'],
            array_column($scanner->references($document), 'name'),
        );
        self::assertSame(
            ['dynamic', 'dynamic', 'dynamic', 'static', 'static'],
            array_column($scanner->references($document, includeDynamic: true), 'status'),
        );
        $result = wait((new SemanticQueryHandler($root))->diagnoseProject());
        self::assertSame('ok', $result['status']);
        self::assertSame(['missing.html.twig', 'missing.html.twig'], array_column($result['data']['items'], 'subject'));
        self::assertContains('dynamic_template_references', $result['data']['skippedChecks']);
    }
}
