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

        $response = wait($middleware->process($request, $this->unreachableHandler()));

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

    public function testReturnsEmptyHoverForUnresolvedResourceWithoutDelegating(): void
    {
        $source = "<?php\nuri('app://self/missing');\n";
        [$middleware, $request] = $this->middleware($source, 'self/missing');

        $response = wait($middleware->process($request, $this->unreachableHandler()));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertNull($response->result);
    }

    public function testReturnsEmptyHoverWhenOpenDocumentIsOutsideSemanticWorkspace(): void
    {
        $source = "<?php\nuri('app://self/user');\n";
        $outsideFile = realpath(dirname(__DIR__, 3) . '/composer.json');
        self::assertNotFalse($outsideFile);
        [$middleware, $request] = $this->middleware($source, 'self/user', $outsideFile);

        $response = wait($middleware->process($request, $this->unreachableHandler()));

        self::assertInstanceOf(ResponseMessage::class, $response);
        self::assertNull($response->result);
    }

    /** @return array{BearHoverMiddleware,RequestMessage} */
    private function middleware(string $source, string $needle, ?string $file = null): array
    {
        $file ??= self::fixture() . '/src/Client.php';
        $uri = 'file://' . $file;
        $workspace = new Workspace();
        $workspace->open(new TextDocumentItem($uri, 'php', 1, $source));
        $offset = strpos($source, $needle);
        self::assertNotFalse($offset);
        $position = PositionConverter::intByteOffsetToPosition($offset, $source);

        return [
            new BearHoverMiddleware($workspace, self::fixture()),
            new RequestMessage(1, 'textDocument/hover', [
                'textDocument' => ['uri' => $uri],
                'position' => ['line' => $position->line, 'character' => $position->character],
            ]),
        ];
    }

    private function unreachableHandler(): RequestHandler
    {
        $middleware = $this->createMock(Middleware::class);
        $middleware->expects(self::never())->method('process');

        return new RequestHandler([$middleware]);
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
}
