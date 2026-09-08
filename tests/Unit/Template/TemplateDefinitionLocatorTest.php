<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Template;

use Suzumaze\BearPhpactor\Resource\LanguageServer\ResourceUriDocumentLinkHandler;
use Suzumaze\BearPhpactor\Resource\ReferenceFinder\ResourceDefinitionLocator;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Template\TemplateDefinitionLocator;
use Phpactor\Extension\LanguageServerBridge\Converter\LocationConverter;
use Phpactor\Extension\LanguageServerBridge\Converter\PositionConverter;
use Phpactor\Extension\LanguageServerReferenceFinder\Handler\GotoDefinitionHandler;
use Phpactor\LanguageServer\LanguageServerTesterBuilder;
use Phpactor\LanguageServer\Test\ProtocolFactory;
use Phpactor\LanguageServerProtocol\DefinitionParams;
use Phpactor\LanguageServerProtocol\Location as LspLocation;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use Phpactor\TextDocument\FilesystemTextDocumentLocator;
use Phpactor\TextDocument\TextDocumentUri;
use PHPUnit\Framework\TestCase;

final class TemplateDefinitionLocatorTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $root = realpath(__DIR__ . '/../../Fixture/Template');
        self::assertNotFalse($root);
        $this->projectRoot = $root;
    }

    /**
     * @dataProvider twigReferenceProvider
     */
    public function testTwigStaticReferenceJumps(string $needle, string $target): void
    {
        $location = $this->requestDefinition('src/Resource/Page/TwigReferences.html.twig', $needle, 'twig');

        self::assertInstanceOf(LspLocation::class, $location);
        self::assertSame($this->uri($target), $location->uri);
    }

    /** @return iterable<string, array{string, string}> */
    public static function twigReferenceProvider(): iterable
    {
        yield 'block second argument' => [
            'Page/Content/Index.html.twig',
            'src/Resource/Page/Content/Index.html.twig',
        ];
        yield 'include tag' => [
            'element/component/structured_data.html.twig',
            'var/templates/element/component/structured_data.html.twig',
        ];
        yield 'extends tag' => [
            'layout/contents_default.html.twig',
            'src/Resource/layout/contents_default.html.twig',
        ];
        yield 'include function' => [
            'element/component/card.html.twig',
            'var/templates/element/component/card.html.twig',
        ];
    }

    /**
     * @dataProvider qiqReferenceProvider
     */
    public function testQiqStaticReferenceJumps(string $needle, string $target): void
    {
        $location = $this->requestDefinition('var/qiq/template/Page/QiqReferences.php', $needle, 'php');

        self::assertInstanceOf(LspLocation::class, $location);
        self::assertSame($this->uri($target), $location->uri);
    }

    /** @return iterable<string, array{string, string}> */
    public static function qiqReferenceProvider(): iterable
    {
        yield 'layout' => ['layout/base', 'var/qiq/template/layout/base.php'];
        yield 'root name with leading slash' => [
            '/partial/structuredData/webSite',
            'var/qiq/template/partial/structuredData/webSite.php',
        ];
        yield 'inheritance' => ['layout/parent', 'var/qiq/template/layout/parent.php'];
        yield 'explicit this helper' => ['partial/card', 'var/qiq/template/partial/card.php'];
        yield 'native PHP' => ['partial/native', 'var/qiq/template/partial/native.php'];
    }

    /**
     * @dataProvider qiqRelativeReferenceProvider
     */
    public function testQiqRelativeReferenceUsesCurrentTemplate(string $needle, string $target): void
    {
        $location = $this->requestDefinition(
            'var/qiq/template/Page/Nested/RelativeReferences.php',
            $needle,
            'php',
        );

        self::assertInstanceOf(LspLocation::class, $location);
        self::assertSame($this->uri($target), $location->uri);
    }

    /** @return iterable<string, array{string, string}> */
    public static function qiqRelativeReferenceProvider(): iterable
    {
        yield 'same directory' => ['./sibling', 'var/qiq/template/Page/Nested/sibling.php'];
        yield 'parent directory' => ['../parent', 'var/qiq/template/Page/parent.php'];
    }

    public function testTwigBlockNameIsNotAFileReference(): void
    {
        self::assertNull($this->requestDefinition(
            'src/Resource/Page/TwigReferences.html.twig',
            'main_bottom',
            'twig',
        ));
    }

    public function testDynamicReferencesAreIgnored(): void
    {
        self::assertNull($this->requestDefinition(
            'src/Resource/Page/TwigReferences.html.twig',
            'dynamic_template',
            'twig',
        ));
        self::assertNull($this->requestDefinition(
            'var/qiq/template/Page/QiqReferences.php',
            '$dynamic',
            'php',
        ));
    }

    public function testReferencesInCommentsAndVerbatimAreIgnored(): void
    {
        self::assertNull($this->requestDefinition(
            'src/Resource/Page/TwigReferences.html.twig',
            'missing/commented.html.twig',
            'twig',
        ));
        self::assertNull($this->requestDefinition(
            'src/Resource/Page/TwigReferences.html.twig',
            'missing/verbatim.html.twig',
            'twig',
        ));
        self::assertNull($this->requestDefinition(
            'var/qiq/template/Page/QiqReferences.php',
            'missing/commented',
            'php',
        ));
    }

    public function testQiqCannotEscapeItsCatalogRoot(): void
    {
        self::assertNull($this->requestDefinition(
            'var/qiq/template/Page/Nested/RelativeReferences.php',
            '../../../outside',
            'php',
        ));
    }

    public function testOrdinaryPhpRenderCallIsNotTreatedAsQiq(): void
    {
        self::assertNull($this->requestDefinition('src/PlainPhp.php', 'partial/card', 'php'));
    }

    public function testDocumentLinkCoversTheWholeQiqTemplateName(): void
    {
        $relativePath = 'var/qiq/template/Page/QiqReferences.php';
        $path = $this->projectRoot . '/' . $relativePath;
        $text = (string) file_get_contents($path);
        $uri = TextDocumentUri::fromString($path)->__toString();

        $builder = LanguageServerTesterBuilder::createBare()->enableTextDocuments();
        $tester = $builder->addHandler(new ResourceUriDocumentLinkHandler(
            $builder->workspace(),
            new ResourceDefinitionLocator(new StringLiteralAtOffset()),
            new TemplateDefinitionLocator(),
        ))->build();
        $tester->textDocument()->open($uri, $text);

        $response = $tester->requestAndWait('textDocument/documentLink', [
            'textDocument' => ProtocolFactory::textDocumentIdentifier($uri),
        ]);
        self::assertNotNull($response);
        $tester->assertSuccess($response);

        $linkedNames = [];
        $lines = explode("\n", $text);
        foreach ((array) $response->result as $link) {
            $line = $lines[$link->range->start->line];
            $linkedNames[] = substr(
                $line,
                $link->range->start->character,
                $link->range->end->character - $link->range->start->character,
            );
        }

        self::assertContains('/partial/structuredData/webSite', $linkedNames);
        self::assertNotContains('missing/commented', $linkedNames);
    }

    private function requestDefinition(string $relativePath, string $needle, string $language): ?LspLocation
    {
        $path = $this->projectRoot . '/' . $relativePath;
        $text = (string) file_get_contents($path);
        $uri = TextDocumentUri::fromString($path)->__toString();
        $needleOffset = strpos($text, $needle);
        self::assertNotFalse($needleOffset, sprintf('Needle "%s" not found in %s', $needle, $relativePath));

        $builder = LanguageServerTesterBuilder::create();
        $tester = $builder->addHandler(new GotoDefinitionHandler(
            $builder->workspace(),
            new TemplateDefinitionLocator(),
            new LocationConverter(new FilesystemTextDocumentLocator()),
            $builder->clientApi(),
        ))->build();
        $tester->initialize();
        $builder->workspace()->open(new TextDocumentItem($uri, $language, 1, $text));

        $response = $tester->requestAndWait('textDocument/definition', new DefinitionParams(
            new TextDocumentIdentifier($uri),
            PositionConverter::intByteOffsetToPosition($needleOffset + 1, $text),
        ));
        self::assertNotNull($response);
        self::assertNull($response->error);

        return $response->result instanceof LspLocation ? $response->result : null;
    }

    private function uri(string $relativePath): string
    {
        return TextDocumentUri::fromString($this->projectRoot . '/' . $relativePath)->__toString();
    }
}
