<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Template;

use Phpactor\Extension\LanguageServerBridge\Converter\LocationConverter;
use Phpactor\Extension\LanguageServerBridge\Converter\PositionConverter;
use Phpactor\Extension\LanguageServerReferenceFinder\Handler\GotoDefinitionHandler;
use Phpactor\LanguageServer\Core\Workspace\Workspace;
use Phpactor\LanguageServer\LanguageServerTesterBuilder;
use Phpactor\LanguageServerProtocol\DefinitionParams;
use Phpactor\LanguageServerProtocol\Location as LspLocation;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\ReferenceFinder\ChainDefinitionLocationProvider;
use Phpactor\TextDocument\FilesystemTextDocumentLocator;
use Phpactor\TextDocument\TextDocumentUri;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Template\EmbedTemplateDefinitionLocator;

/**
 * Twig/Qiq の #[Embed] テンプレートジャンプを、実際の
 * textDocument/definition リクエスト経由で確認する。
 */
final class EmbedTemplateDefinitionLocatorTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $projectRoot = realpath(__DIR__ . '/../../Fixture/Template/basic');
        self::assertNotFalse($projectRoot, 'Template fixture project not found');
        $this->projectRoot = $projectRoot;
    }

    public function testTwigEmbedVariableJumpsToEmbeddedTwigTemplate(): void
    {
        $location = $this->requestDefinition(
            'var/templates/App/Dashboard.html.twig',
            'twig',
            'user',
        );

        self::assertInstanceOf(LspLocation::class, $location);
        self::assertSame($this->uri('var/templates/App/User.html.twig'), $location->uri);
    }

    public function testQiqEmbedVariableJumpsToEmbeddedQiqTemplate(): void
    {
        $location = $this->requestDefinition(
            'var/qiq/template/App/Dashboard.php',
            'qiq',
            '$this->user',
            strlen('$this->'),
        );

        self::assertInstanceOf(LspLocation::class, $location);
        self::assertSame($this->uri('var/qiq/template/App/User.php'), $location->uri);
    }

    public function testQiqEscapedOutputVariableJumpsToEmbeddedQiqTemplate(): void
    {
        $location = $this->requestDefinition(
            'var/qiq/template/App/Dashboard.php',
            'qiq',
            '$this->user',
            strlen('$this->'),
            1,
        );

        self::assertInstanceOf(LspLocation::class, $location);
        self::assertSame($this->uri('var/qiq/template/App/User.php'), $location->uri);
    }

    public function testReturnsNothingOutsideSupportedTwigReference(): void
    {
        $location = $this->requestDefinition(
            'var/templates/App/Dashboard.html.twig',
            'twig',
            'title',
        );

        self::assertNull($location);
    }

    public function testReturnsNothingForTwigPropertyAccess(): void
    {
        $location = $this->requestDefinition(
            'var/templates/App/Dashboard.html.twig',
            'twig',
            'user.name',
        );

        self::assertNull($location);
    }

    public function testReturnsNothingForQiqCodeTagAndPropertyAccess(): void
    {
        $codeTagLocation = $this->requestDefinition(
            'var/qiq/template/App/Dashboard.php',
            'qiq',
            '{{ $this->user }}',
            strlen('{{ $this->'),
        );
        $propertyLocation = $this->requestDefinition(
            'var/qiq/template/App/Dashboard.php',
            'qiq',
            '$this->user->name',
            strlen('$this->'),
        );

        self::assertNull($codeTagLocation);
        self::assertNull($propertyLocation);
    }

    public function testReturnsNothingForMissingTraversalAndAmbiguousEmbedTargets(): void
    {
        $missing = $this->requestDefinition(
            'var/templates/App/Dashboard.html.twig',
            'twig',
            'missing',
        );
        $traversal = $this->requestDefinition(
            'var/qiq/template/App/Dashboard.php',
            'qiq',
            'escape',
        );
        $ambiguous = $this->requestDefinition(
            'var/templates/App/Dashboard.html.twig',
            'twig',
            'duplicate',
        );

        self::assertNull($missing);
        self::assertNull($traversal);
        self::assertNull($ambiguous);
    }

    public function testReturnsNothingForMalformedTemplates(): void
    {
        $twig = $this->requestDefinition(
            'var/templates/App/Dashboard.html.twig',
            'twig',
            '{{ user',
        );
        $qiq = $this->requestDefinition(
            'var/qiq/template/App/Dashboard.php',
            'qiq',
            '{{= $this->user',
        );

        self::assertNull($twig);
        self::assertNull($qiq);
    }

    private function requestDefinition(
        string $relativePath,
        string $languageId,
        string $needle,
        int $needleOffset = 0,
        int $occurrence = 0,
    ): ?LspLocation {
        $path = $this->projectRoot . '/' . $relativePath;
        $text = (string) file_get_contents($path);
        $uri = $this->uri($relativePath);
        $needlePosition = $this->occurrenceOffset($text, $needle, $occurrence);

        $builder = LanguageServerTesterBuilder::create();
        $tester = $builder
            ->addHandler(new GotoDefinitionHandler(
                $builder->workspace(),
                new ChainDefinitionLocationProvider([new EmbedTemplateDefinitionLocator()]),
                new LocationConverter(new FilesystemTextDocumentLocator()),
                $builder->clientApi(),
            ))
            ->build();

        $this->open($builder->workspace(), $uri, $languageId, $text);

        $position = PositionConverter::intByteOffsetToPosition($needlePosition + $needleOffset, $text);
        $response = $tester->requestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            $position,
        ));
        self::assertNotNull($response);
        $tester->assertSuccess($response);

        return $response->result instanceof LspLocation ? $response->result : null;
    }

    private function open(Workspace $workspace, string $uri, string $languageId, string $text): void
    {
        $workspace->open(new TextDocumentItem($uri, $languageId, 1, $text));
    }

    private function occurrenceOffset(string $text, string $needle, int $occurrence): int
    {
        $offset = -1;
        for ($index = 0; $index <= $occurrence; $index++) {
            $offset = strpos($text, $needle, $offset + 1);
            self::assertNotFalse($offset, sprintf('Needle "%s" not found', $needle));
        }

        return $offset;
    }

    private function uri(string $relativePath): string
    {
        return TextDocumentUri::fromString($this->projectRoot . '/' . $relativePath)->__toString();
    }
}
