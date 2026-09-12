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

    /** @return array{BearHoverMiddleware,RequestMessage} */
    private function middleware(
        string $source,
        string $needle,
        ?string $file = null,
        ?string $workspaceRoot = null,
    ): array {
        $workspaceRoot ??= self::fixture();
        $file ??= $workspaceRoot . '/src/Client.php';
        $uri = 'file://' . $file;
        $workspace = new Workspace();
        $workspace->open(new TextDocumentItem($uri, 'php', 1, $source));
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
