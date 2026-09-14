<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\LanguageServer;

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
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\BearHoverMiddleware;

use function Amp\Promise\wait;

final class BearHoverMiddlewareTest extends TestCase
{
    public function testReturnsStandardHoverForResourceUri(): void
    {
        $source = "<?php\nuri('app://self/user');\n";
        [$middleware, $request] = $this->middleware($source, 'self/user');

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertInstanceOf(Hover::class, $response->result);
        self::assertInstanceOf(MarkupContent::class, $response->result->contents);
        self::assertSame('markdown', $response->result->contents->kind);
        self::assertStringContainsString(
            '**BEAR Resource** `app://self/user`',
            $response->result->contents->value,
        );
        self::assertStringContainsString('`Acme\\Blog\\Resource\\App\\User`', $response->result->contents->value);
        self::assertStringContainsString('`src/Resource/App/User.php`', $response->result->contents->value);
        self::assertStringContainsString('`onGet()`', $response->result->contents->value);
        self::assertSame(1, $response->result->range->start->line);
        self::assertSame(5, $response->result->range->start->character);
        self::assertSame(20, $response->result->range->end->character);
    }

    public function testDelegatesNonBearPositionToPhpactorMiddlewareStack(): void
    {
        $source = "<?php\nstrlen('value');\n";
        [$middleware, $request] = $this->middleware($source, 'strlen');
        $expected = new ResponseMessage(1, new Hover('Phpactor fallback'));

        self::assertSame($expected, wait($middleware->process($request, $this->fallbackHandler($expected))));
    }

    public function testReturnsEmptyHoverForUnresolvedResourceWithoutPhpactorFallback(): void
    {
        $source = "<?php\nuri('app://self/missing');\n";
        [$middleware, $request] = $this->middleware($source, 'self/missing');

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertNull($response->result);
    }

    public function testReturnsEmptyHoverWhenOpenDocumentIsOutsideSemanticWorkspace(): void
    {
        $source = "<?php\nuri('app://self/user');\n";
        $outsideFile = realpath(dirname(__DIR__, 3) . '/composer.json');
        self::assertNotFalse($outsideFile);
        [$middleware, $request] = $this->middleware($source, 'self/user', $outsideFile);

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertNull($response->result);
    }

    public function testReturnsStandardHoverForAlpsDescriptor(): void
    {
        $root = self::alpsFixture();
        $file = $root . '/src/Resource/App/AlpsDemo.php';
        $source = (string) file_get_contents($file);
        $source = str_replace(['<caret-1>', '<caret-2>', '<caret-3>', '<caret-4>'], '', $source);
        [$middleware, $request] = $this->middleware($source, 'goArticle', $file, $root);

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertInstanceOf(Hover::class, $response->result);
        self::assertInstanceOf(MarkupContent::class, $response->result->contents);
        $markdown = $response->result->contents->value;
        self::assertStringContainsString('**ALPS descriptor** `goArticle`', $markdown);
        self::assertStringContainsString('Profile: `var/alps/profile.json`', $markdown);
        self::assertStringContainsString('Type: `safe`', $markdown);
        self::assertStringContainsString('Title: `View Article`', $markdown);
        self::assertStringContainsString('- `rt` → `Article` (ok)', $markdown);
        self::assertStringContainsString('- `href` ← `Article` (ok)', $markdown);
        $start = PositionConverter::intByteOffsetToPosition((int) strpos($source, 'goArticle'), $source);
        $end = PositionConverter::intByteOffsetToPosition(
            (int) strpos($source, 'goArticle') + strlen('goArticle'),
            $source,
        );
        self::assertEquals($start, $response->result->range->start);
        self::assertEquals($end, $response->result->range->end);
    }

    public function testReturnsEmptyHoverForUnresolvedAlpsDescriptorWithoutPhpactorFallback(): void
    {
        $root = self::alpsFixture();
        $file = $root . '/src/Resource/App/AlpsDemo.php';
        $source = (string) file_get_contents($file);
        $source = str_replace(['<caret-1>', '<caret-2>', '<caret-3>', '<caret-4>'], '', $source);
        [$middleware, $request] = $this->middleware($source, 'noSuchDescriptor', $file, $root);

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertNull($response->result);
    }

    public function testDelegatesAlpsSecondArgumentToPhpactorMiddlewareStack(): void
    {
        $root = self::alpsFixture();
        $file = $root . '/src/Resource/App/AlpsDemo.php';
        $source = "<?php\nuse BEAR\\ApiDoc\\Annotation\\Alps;\n"
            . "#[Alps('goArticle', 'secondValue')]\nfinal class Demo {}\n";
        [$middleware, $request] = $this->middleware($source, 'secondValue', $file, $root);
        $expected = new ResponseMessage(1, new Hover('Phpactor fallback'));

        self::assertSame($expected, wait($middleware->process($request, $this->fallbackHandler($expected))));
    }

    public function testReturnsEmptyAlpsHoverWhenOpenDocumentIsOutsideSemanticWorkspace(): void
    {
        $root = self::alpsFixture();
        $outsideFile = realpath(dirname(__DIR__, 3) . '/composer.json');
        self::assertNotFalse($outsideFile);
        $source = "<?php\nuse BEAR\\ApiDoc\\Annotation\\Alps;\n"
            . "#[Alps('goArticle')]\nfinal class Demo {}\n";
        [$middleware, $request] = $this->middleware($source, 'goArticle', $outsideFile, $root);

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertNull($response->result);
    }

    public function testReturnsStandardHoverForResponseJsonSchemaReference(): void
    {
        $root = self::jsonSchemaFixture();
        $file = $root . '/src/Resource/App/SchemaDemo.php';
        $source = str_replace(
            ['<caret-1>', '<caret-2>', '<caret-3>', '<caret-4>', '<caret-5>'],
            '',
            (string) file_get_contents($file),
        );
        [$middleware, $request] = $this->middleware($source, 'user.json', $file, $root);

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertInstanceOf(Hover::class, $response->result);
        self::assertInstanceOf(MarkupContent::class, $response->result->contents);
        $markdown = $response->result->contents->value;
        self::assertStringContainsString('**BEAR JSON Schema**', $markdown);
        self::assertStringContainsString('Kind: `response`', $markdown);
        self::assertStringContainsString('Path: `var/json_schema/user.json`', $markdown);
        self::assertStringContainsString('Top-level type: `object`', $markdown);
        self::assertStringContainsString('- `age`: `integer` (optional)', $markdown);
        self::assertStringContainsString('- `name`: `string` (required)', $markdown);

        $start = PositionConverter::intByteOffsetToPosition((int) strpos($source, 'user.json'), $source);
        $end = PositionConverter::intByteOffsetToPosition(
            (int) strpos($source, 'user.json') + strlen('user.json'),
            $source,
        );
        self::assertEquals($start, $response->result->range->start);
        self::assertEquals($end, $response->result->range->end);
    }

    public function testReturnsStandardHoverForRequestJsonSchemaReference(): void
    {
        $root = self::jsonSchemaFixture();
        $file = $root . '/src/Resource/App/SchemaDemo.php';
        $source = str_replace(
            ['<caret-1>', '<caret-2>', '<caret-3>', '<caret-4>', '<caret-5>'],
            '',
            (string) file_get_contents($file),
        );
        [$middleware, $request] = $this->middleware($source, 'user-params.json', $file, $root);

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertInstanceOf(Hover::class, $response->result);
        $markdown = $response->result->contents->value;
        self::assertStringContainsString('Kind: `request`', $markdown);
        self::assertStringContainsString('Path: `var/json_validate/user-params.json`', $markdown);
        self::assertStringContainsString('- `id`: `integer` (optional)', $markdown);
    }

    public function testReturnsEmptyHoverForMissingJsonSchemaWithoutPhpactorFallback(): void
    {
        $root = self::jsonSchemaFixture();
        $file = $root . '/src/Resource/App/SchemaDemo.php';
        $source = "<?php\nuse BEAR\Resource\Annotation\JsonSchema;\n#[JsonSchema('missing.json')]\n";
        [$middleware, $request] = $this->middleware($source, 'missing.json', $file, $root);

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertNull($response->result);
    }

    public function testDelegatesNonSchemaJsonSchemaArgumentsAndQuoteBoundary(): void
    {
        $root = self::jsonSchemaFixture();
        $file = $root . '/src/Resource/App/SchemaDemo.php';
        $source = "<?php\nuse BEAR\Resource\Annotation\JsonSchema;\n"
            . "#[JsonSchema(key: 'id', target: 'query')]\n";
        $expected = new ResponseMessage(1, new Hover('Phpactor fallback'));

        [$keyMiddleware, $keyRequest] = $this->middleware($source, 'id', $file, $root);
        self::assertSame(
            $expected,
            wait($keyMiddleware->process($keyRequest, $this->fallbackHandler($expected))),
        );

        $schemaSource = "<?php\nuse BEAR\Resource\Annotation\JsonSchema;\n#[JsonSchema('user.json')]\n";
        [$quoteMiddleware, $quoteRequest] = $this->middleware($schemaSource, "'user", $file, $root);
        self::assertSame(
            $expected,
            wait($quoteMiddleware->process($quoteRequest, $this->fallbackHandler($expected))),
        );
    }

    public function testJsonSchemaRecognitionTakesPrecedenceOverResourceUri(): void
    {
        $root = self::jsonSchemaFixture();
        $file = $root . '/src/Resource/App/SchemaDemo.php';
        $source = "<?php\nuse BEAR\Resource\Annotation\JsonSchema;\n#[JsonSchema('app://self/user')]\n";
        [$middleware, $request] = $this->middleware($source, 'app://self/user', $file, $root);

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertNull($response->result);
    }

    public function testReturnsEmptyJsonSchemaHoverWhenOpenDocumentIsOutsideSemanticWorkspace(): void
    {
        $root = self::jsonSchemaFixture();
        $outsideFile = realpath(dirname(__DIR__, 3) . '/composer.json');
        self::assertNotFalse($outsideFile);
        $source = "<?php\nuse BEAR\Resource\Annotation\JsonSchema;\n#[JsonSchema('user.json')]\n";
        [$middleware, $request] = $this->middleware($source, 'user.json', $outsideFile, $root);

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertNull($response->result);
    }

    public function testBoundsJsonSchemaPropertiesAndProducesValidMarkdownAndUtf8(): void
    {
        $root = sys_get_temp_dir() . '/bear-schema-hover-' . bin2hex(random_bytes(8));
        try {
            self::assertTrue(mkdir($root . '/src', 0777, true));
            self::assertTrue(mkdir($root . '/var/json_schema', 0777, true));
            self::assertNotFalse(file_put_contents(
                $root . '/composer.json',
                '{"autoload":{"psr-4":{"Acme\\\\App\\\\":"src/"}}}',
            ));

            $properties = ['00``' . str_repeat('あ', 600) => ['type' => 'string']];
            for ($index = 1; $index < 25; $index++) {
                $properties[sprintf('property%02d', $index)] = ['type' => 'string'];
            }
            self::assertNotFalse(file_put_contents(
                $root . '/var/json_schema/many.json',
                json_encode(
                    ['type' => 'object', 'properties' => $properties],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
            ));

            $source = "<?php\nuse BEAR\\Resource\\Annotation\\JsonSchema;\n#[JsonSchema('many.json')]\n";
            $file = $root . '/src/Demo.php';
            self::assertNotFalse(file_put_contents($file, $source));
            [$middleware, $request] = $this->middleware($source, 'many.json', $file, $root);

            $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

            self::assertInstanceOf(ResponseMessage::class, $response);
            self::assertInstanceOf(Hover::class, $response->result);
            $markdown = $response->result->contents->value;
            self::assertSame(1, preg_match('//u', $markdown));
            self::assertStringContainsString('- ```00``あ', $markdown);
            self::assertSame(20, substr_count($markdown, ' (optional)'));
            self::assertStringContainsString('- … 5 more', $markdown);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testReturnsStandardHoverForTwigTemplateReference(): void
    {
        $root = self::templateFixture();
        $file = $root . '/src/Resource/Page/TwigReferences.html.twig';
        $source = (string) file_get_contents($file);
        [$middleware, $request] = $this->middleware(
            $source,
            'element/component/card.html.twig',
            $file,
            $root,
            'twig',
        );

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertInstanceOf(Hover::class, $response->result);
        self::assertInstanceOf(MarkupContent::class, $response->result->contents);
        $markdown = $response->result->contents->value;
        self::assertStringContainsString('**BEAR Template**', $markdown);
        self::assertStringContainsString('Engine: `twig`', $markdown);
        self::assertStringContainsString('Name: `element/component/card.html.twig`', $markdown);
        self::assertStringContainsString('Path: `var/templates/element/component/card.html.twig`', $markdown);

        $start = PositionConverter::intByteOffsetToPosition(
            (int) strpos($source, 'element/component/card.html.twig'),
            $source,
        );
        $end = PositionConverter::intByteOffsetToPosition(
            (int) strpos($source, 'element/component/card.html.twig')
                + strlen('element/component/card.html.twig'),
            $source,
        );
        self::assertEquals($start, $response->result->range->start);
        self::assertEquals($end, $response->result->range->end);
    }

    public function testReturnsTwigHoverWhenClientAssociatesTwigWithPhp(): void
    {
        $root = self::templateFixture();
        $file = $root . '/src/Resource/Page/TwigReferences.html.twig';
        $source = (string) file_get_contents($file);
        [$middleware, $request] = $this->middleware(
            $source,
            'element/component/structured_data.html.twig',
            $file,
            $root,
        );

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertInstanceOf(Hover::class, $response->result);
        self::assertStringContainsString('Engine: `twig`', $response->result->contents->value);
    }

    public function testReturnsStandardHoverForRelativeQiqTemplateReference(): void
    {
        $root = self::templateFixture();
        $file = $root . '/var/qiq/template/Page/Nested/RelativeReferences.php';
        $source = (string) file_get_contents($file);
        [$middleware, $request] = $this->middleware($source, './sibling', $file, $root, 'qiq');

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertInstanceOf(Hover::class, $response->result);
        $markdown = $response->result->contents->value;
        self::assertStringContainsString('Engine: `qiq`', $markdown);
        self::assertStringContainsString('Name: `./sibling`', $markdown);
        self::assertStringContainsString('Path: `var/qiq/template/Page/Nested/sibling.php`', $markdown);
    }

    public function testReturnsQiqHoverWhenCanonicalTemplateIsAssociatedWithPhp(): void
    {
        $root = self::templateFixture();
        $file = $root . '/var/qiq/template/Page/QiqReferences.php';
        $source = (string) file_get_contents($file);
        [$middleware, $request] = $this->middleware($source, 'layout/base', $file, $root);

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertInstanceOf(Hover::class, $response->result);
        $markdown = $response->result->contents->value;
        self::assertStringContainsString('Engine: `qiq`', $markdown);
        self::assertStringContainsString('Name: `layout/base`', $markdown);
        self::assertStringContainsString('Path: `var/qiq/template/layout/base.php`', $markdown);
    }

    public function testReturnsEmptyHoverForUnresolvedStaticTemplateWithoutPhpactorFallback(): void
    {
        $root = self::templateFixture();
        $file = $root . '/src/Resource/Page/TwigReferences.html.twig';
        $source = "{{ include('missing/static.html.twig') }}\n";
        [$middleware, $request] = $this->middleware(
            $source,
            'missing/static.html.twig',
            $file,
            $root,
            'twig',
        );

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertNull($response->result);
    }

    public function testDelegatesDynamicTemplateReferenceToPhpactorMiddlewareStack(): void
    {
        $root = self::templateFixture();
        $file = $root . '/src/Resource/Page/TwigReferences.html.twig';
        $source = "{{ include(dynamic_template) }}\n";
        [$middleware, $request] = $this->middleware($source, 'dynamic_template', $file, $root, 'twig');
        $expected = new ResponseMessage(1, new Hover('Phpactor fallback'));

        self::assertSame($expected, wait($middleware->process($request, $this->fallbackHandler($expected))));
    }

    public function testDoesNotTreatQiqLookingTagInOrdinaryPhpFileAsTemplateHover(): void
    {
        $root = self::templateFixture();
        $file = $root . '/src/PlainPhp.php';
        $source = "<?php\n\$text = \"{{= render('partial/card') }}\";\n";
        [$middleware, $request] = $this->middleware($source, 'partial/card', $file, $root);
        $expected = new ResponseMessage(1, new Hover('Phpactor fallback'));

        self::assertSame($expected, wait($middleware->process($request, $this->fallbackHandler($expected))));
    }

    public function testDelegatesTemplateQuoteBoundaryToPhpactorMiddlewareStack(): void
    {
        $root = self::templateFixture();
        $file = $root . '/src/Resource/Page/TwigReferences.html.twig';
        $source = "{{ include('element/component/card.html.twig') }}\n";
        [$middleware, $request] = $this->middleware($source, "'element", $file, $root, 'twig');
        $expected = new ResponseMessage(1, new Hover('Phpactor fallback'));

        self::assertSame($expected, wait($middleware->process($request, $this->fallbackHandler($expected))));
    }

    public function testResourceUriTakesPrecedenceInPhpAssociatedQiqDocument(): void
    {
        $root = self::templateResourceFixture();
        $file = $root . '/var/qiq/template/App/User.php';
        $source = "<?php uri('app://self/user'); ?>\n{{= render('partial/card') }}\n";
        [$middleware, $request] = $this->middleware($source, 'app://self/user', $file, $root);

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertInstanceOf(Hover::class, $response->result);
        self::assertStringContainsString(
            '**BEAR Resource** `app://self/user`',
            $response->result->contents->value,
        );
    }

    public function testReturnsEmptyTemplateHoverWhenOpenDocumentIsOutsideSemanticWorkspace(): void
    {
        $root = self::templateFixture();
        $outsideFile = realpath(dirname(__DIR__, 3) . '/composer.json');
        self::assertNotFalse($outsideFile);
        $source = "{{ include('element/component/card.html.twig') }}\n";
        [$middleware, $request] = $this->middleware(
            $source,
            'element/component/card.html.twig',
            $outsideFile,
            $root,
            'twig',
        );

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertNull($response->result);
    }

    public function testBoundsRelationshipsAndProducesValidMarkdownAndUtf8(): void
    {
        $root = sys_get_temp_dir() . '/bear-alps-hover-' . bin2hex(random_bytes(8));
        try {
            self::assertTrue(mkdir($root . '/src', 0777, true));
            self::assertTrue(mkdir($root . '/var/alps', 0777, true));
            self::assertNotFalse(file_put_contents(
                $root . '/composer.json',
                '{"autoload":{"psr-4":{"Acme\\\\App\\\\":"src/"}}}',
            ));
            self::assertNotFalse(file_put_contents(
                $root . '/apidoc.xml',
                '<apidoc><alps>var/alps/profile.json</alps></apidoc>',
            ));
            $source = "<?php\nuse BEAR\\ApiDoc\\Annotation\\Alps;\n#[Alps('parent')]\nfinal class Demo {}\n";
            $file = $root . '/src/Demo.php';
            self::assertNotFalse(file_put_contents($file, $source));

            $children = [];
            $descriptors = [];
            for ($index = 0; $index < 25; $index++) {
                $target = sprintf('target%02d', $index);
                $children[] = ['href' => '#' . $target];
                $descriptors[] = ['id' => $target];
            }
            array_unshift($descriptors, [
                'id' => 'parent',
                'title' => '``' . str_repeat('あ', 600) . '```',
                'descriptor' => $children,
            ]);
            self::assertNotFalse(file_put_contents(
                $root . '/var/alps/profile.json',
                json_encode(['alps' => ['descriptor' => $descriptors]], JSON_THROW_ON_ERROR),
            ));

            [$middleware, $request] = $this->middleware($source, 'parent', $file, $root);
            $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

            self::assertInstanceOf(ResponseMessage::class, $response);
            self::assertInstanceOf(Hover::class, $response->result);
            self::assertInstanceOf(MarkupContent::class, $response->result->contents);
            $markdown = $response->result->contents->value;
            self::assertSame(1, preg_match('//u', $markdown));
            self::assertStringContainsString('Title: ``` ``あ', $markdown);
            self::assertStringContainsString('… ```', $markdown);
            self::assertSame(20, substr_count($markdown, '- `href` →'));
            self::assertStringContainsString('- … 5 more', $markdown);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testReturnsStandardHoverForRouteName(): void
    {
        $root = self::routeFixture();
        $file = $root . '/aura.route.php';
        $source = "<?php\n\$map->route('/index', '/index');\n";
        [$middleware, $request] = $this->middleware($source, '/index', $file, $root);

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertInstanceOf(Hover::class, $response->result);
        self::assertInstanceOf(MarkupContent::class, $response->result->contents);
        $markdown = $response->result->contents->value;
        self::assertStringContainsString('**BEAR Route** `/index`', $markdown);
        self::assertStringContainsString('Resource URI: `page://self/index`', $markdown);
        self::assertStringContainsString('Class: `RouterFixture\\Resource\\Page\\Index`', $markdown);
        self::assertStringContainsString('Path: `lib/Resource/Page/Index.php`', $markdown);

        $start = PositionConverter::intByteOffsetToPosition((int) strpos($source, '/index'), $source);
        $end = PositionConverter::intByteOffsetToPosition(
            (int) strpos($source, '/index') + strlen('/index'),
            $source,
        );
        self::assertEquals($start, $response->result->range->start);
        self::assertEquals($end, $response->result->range->end);
    }

    public function testRouteMissingIsEmptyButNonTargetAndQuoteBoundaryDelegate(): void
    {
        $root = self::routeFixture();
        $file = $root . '/aura.route.php';
        $source = (string) file_get_contents($file);
        [$missingMiddleware, $missingRequest] = $this->middleware($source, '/missing', $file, $root);
        $missing = wait($missingMiddleware->process(
            $missingRequest,
            $this->semanticHandler($missingMiddleware),
        ));
        self::assertInstanceOf(ResponseMessage::class, $missing);
        self::assertNull($missing->result);

        foreach (['/../../Client', '/ambiguous'] as $routeName) {
            [$emptyMiddleware, $emptyRequest] = $this->middleware($source, $routeName, $file, $root);
            $empty = wait($emptyMiddleware->process(
                $emptyRequest,
                $this->semanticHandler($emptyMiddleware),
            ));
            self::assertInstanceOf(ResponseMessage::class, $empty);
            self::assertNull($empty->result);
        }

        $expected = new ResponseMessage(1, new Hover('Phpactor fallback'));
        $twoArguments = "<?php\n\$map->route('/index', '/index');\n";
        [$secondMiddleware, $secondRequest] = $this->middleware(
            $twoArguments,
            "'/index');",
            $file,
            $root,
        );
        self::assertSame(
            $expected,
            wait($secondMiddleware->process($secondRequest, $this->fallbackHandler($expected))),
        );

        [$quoteMiddleware, $quoteRequest] = $this->middleware($twoArguments, "'/index',", $file, $root);
        self::assertSame(
            $expected,
            wait($quoteMiddleware->process($quoteRequest, $this->fallbackHandler($expected))),
        );
    }

    public function testReturnsEmptyRouteHoverOutsideWorkspace(): void
    {
        $root = self::routeFixture();
        $source = "<?php\n\$map->route('/index', '/index');\n";
        [$middleware, $request] = $this->middleware($source, '/index', '/tmp/aura.route.php', $root);

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertNull($response->result);
    }

    public function testReturnsStandardHoverForSqlAttributeAndLegacyAnnotation(): void
    {
        $root = self::sqlFixture();
        $attributeFile = $root . '/src/Query/PointQueryInterface.php';
        $attributeSource = (string) file_get_contents($attributeFile);
        [$attributeMiddleware, $attributeRequest] = $this->middleware(
            $attributeSource,
            'point_distance',
            $attributeFile,
            $root,
        );
        $attribute = wait($attributeMiddleware->process(
            $attributeRequest,
            $this->semanticHandler($attributeMiddleware),
        ));

        self::assertInstanceOf(ResponseMessage::class, $attribute);
        self::assertInstanceOf(Hover::class, $attribute->result);
        self::assertInstanceOf(MarkupContent::class, $attribute->result->contents);
        self::assertStringContainsString('**BEAR SQL Query**', $attribute->result->contents->value);
        self::assertStringContainsString('Query ID: `point_distance`', $attribute->result->contents->value);
        self::assertStringContainsString(
            'Path: `var/db/sql/point_distance.sql`',
            $attribute->result->contents->value,
        );

        $legacyFile = $root . '/src/Query/LegacyPointQueryInterface.php';
        $legacySource = (string) file_get_contents($legacyFile);
        [$legacyMiddleware, $legacyRequest] = $this->middleware(
            $legacySource,
            'point_distance',
            $legacyFile,
            $root,
        );
        $legacy = wait($legacyMiddleware->process($legacyRequest, $this->semanticHandler($legacyMiddleware)));
        self::assertInstanceOf(ResponseMessage::class, $legacy);
        self::assertInstanceOf(Hover::class, $legacy->result);
        self::assertStringContainsString('Query ID: `point_distance`', $legacy->result->contents->value);
    }

    public function testSqlMissingInvalidAndResourceLikeIdAreEmptyWithoutFallback(): void
    {
        $root = self::sqlFixture();
        $file = $root . '/src/Query/MissingQueryInterface.php';
        $source = (string) file_get_contents($file);
        [$missingMiddleware, $missingRequest] = $this->middleware($source, 'missing_query', $file, $root);
        $missing = wait($missingMiddleware->process(
            $missingRequest,
            $this->semanticHandler($missingMiddleware),
        ));
        self::assertInstanceOf(ResponseMessage::class, $missing);
        self::assertNull($missing->result);

        $escapeFile = $root . '/src/Query/EscapeQueryInterface.php';
        $escapeSource = (string) file_get_contents($escapeFile);
        [$escapeMiddleware, $escapeRequest] = $this->middleware($escapeSource, '../escape', $escapeFile, $root);
        $escape = wait($escapeMiddleware->process($escapeRequest, $this->semanticHandler($escapeMiddleware)));
        self::assertInstanceOf(ResponseMessage::class, $escape);
        self::assertNull($escape->result);

        $resourceRoot = self::fixture();
        $resourceFile = $resourceRoot . '/src/Client.php';
        $resourceLikeSource = "<?php\nuse Ray\\MediaQuery\\Annotation\\DbQuery;\n"
            . "#[DbQuery('app://self/user')]\ninterface Query {}\n";
        [$resourceMiddleware, $resourceRequest] = $this->middleware(
            $resourceLikeSource,
            'app://self/user',
            $resourceFile,
            $resourceRoot,
        );
        $resourceLike = wait($resourceMiddleware->process(
            $resourceRequest,
            $this->semanticHandler($resourceMiddleware),
        ));
        self::assertInstanceOf(ResponseMessage::class, $resourceLike);
        self::assertNull($resourceLike->result);
    }

    public function testSqlNonIdAttributeAndQuoteBoundaryDelegate(): void
    {
        $root = self::sqlFixture();
        $file = $root . '/src/Query/PointQueryInterface.php';
        $source = (string) file_get_contents($file);
        $expected = new ResponseMessage(1, new Hover('Phpactor fallback'));

        [$laterMiddleware, $laterRequest] = $this->middleware($source, "'row", $file, $root);
        self::assertSame(
            $expected,
            wait($laterMiddleware->process($laterRequest, $this->fallbackHandler($expected))),
        );

        [$quoteMiddleware, $quoteRequest] = $this->middleware($source, "'point_distance", $file, $root);
        self::assertSame(
            $expected,
            wait($quoteMiddleware->process($quoteRequest, $this->fallbackHandler($expected))),
        );

        $namedType = "<?php\nuse Ray\\MediaQuery\\Annotation\\DbQuery;\n#[DbQuery(type: 'point_distance')]\n";
        [$namedMiddleware, $namedRequest] = $this->middleware($namedType, 'point_distance', $file, $root);
        self::assertSame(
            $expected,
            wait($namedMiddleware->process($namedRequest, $this->fallbackHandler($expected))),
        );
    }

    public function testReturnsEmptySqlHoverOutsideWorkspace(): void
    {
        $root = self::sqlFixture();
        $source = "<?php\nuse Ray\\MediaQuery\\Annotation\\DbQuery;\n"
            . "#[DbQuery('point_distance')]\ninterface Query {}\n";
        [$middleware, $request] = $this->middleware($source, 'point_distance', '/tmp/Query.php', $root);

        $response = wait($middleware->process($request, $this->semanticHandler($middleware)));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertNull($response->result);
    }

    /** @return array{BearHoverMiddleware,RequestMessage} */
    private function middleware(
        string $source,
        string $needle,
        ?string $file = null,
        ?string $workspaceRoot = null,
        string $language = 'php',
    ): array {
        $workspaceRoot ??= self::fixture();
        $file ??= $workspaceRoot . '/src/Client.php';
        $uri = 'file://' . $file;
        $workspace = new Workspace();
        $workspace->open(new TextDocumentItem($uri, $language, 1, $source));
        $offset = strpos($source, $needle);
        self::assertNotFalse($offset);
        $position = PositionConverter::intByteOffsetToPosition($offset, $source);

        return [
            new BearHoverMiddleware($workspace, $workspaceRoot),
            new RequestMessage(1, 'textDocument/hover', [
                'textDocument' => ['uri' => $uri],
                'position' => ['line' => $position->line, 'character' => $position->character],
            ]),
        ];
    }

    private function semanticHandler(BearHoverMiddleware $middleware): RequestHandler
    {
        $downstream = $this->createMock(Middleware::class);
        $downstream->expects(self::once())
            ->method('process')
            ->willReturnCallback(static function (Message $request) use ($middleware): Success {
                self::assertInstanceOf(RequestMessage::class, $request);
                self::assertSame('bear/internal/semanticHover', $request->method);

                return new Success(new ResponseMessage(
                    $request->id,
                    wait($middleware->semanticHover($request)),
                ));
            });

        return new RequestHandler([$downstream]);
    }

    private function fallbackHandler(ResponseMessage $expected): RequestHandler
    {
        $middleware = $this->createMock(Middleware::class);
        $middleware->expects(self::once())
            ->method('process')
            ->willReturn(new Success($expected));

        return new RequestHandler([$middleware]);
    }

    private static function fixture(): string
    {
        $fixture = realpath(dirname(__DIR__, 2) . '/Fixture/Resource');
        self::assertNotFalse($fixture);

        return $fixture;
    }

    private static function alpsFixture(): string
    {
        $fixture = realpath(dirname(__DIR__, 2) . '/Fixture/Alps/App1');
        self::assertNotFalse($fixture);

        return $fixture;
    }

    private static function templateFixture(): string
    {
        $fixture = realpath(dirname(__DIR__, 2) . '/Fixture/Template');
        self::assertNotFalse($fixture);

        return $fixture;
    }

    private static function jsonSchemaFixture(): string
    {
        $fixture = realpath(dirname(__DIR__, 2) . '/Fixture/JsonSchema/basic');
        self::assertNotFalse($fixture);

        return $fixture;
    }

    private static function routeFixture(): string
    {
        $fixture = realpath(dirname(__DIR__, 2) . '/Fixture/Router');
        self::assertNotFalse($fixture);

        return $fixture;
    }

    private static function sqlFixture(): string
    {
        $fixture = realpath(dirname(__DIR__, 2) . '/Fixture/Sql/App1');
        self::assertNotFalse($fixture);

        return $fixture;
    }

    private static function templateResourceFixture(): string
    {
        $fixture = realpath(dirname(__DIR__, 2) . '/Fixture/Template/basic');
        self::assertNotFalse($fixture);

        return $fixture;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
