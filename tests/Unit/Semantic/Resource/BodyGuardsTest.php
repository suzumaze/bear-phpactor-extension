<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Resource;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;

use function Amp\Promise\wait;

/**
 * Early-exit guards before the final body assignment, and full assignments that drop `#[Embed]` keys.
 */
final class BodyGuardsTest extends TestCase
{
    public function testGuardReturningThisBeforeTheFinalAssignmentKeepsTheMainPathNames(): void
    {
        $methods = $this->methods('app://self/entries');

        // `'articles' => $this->getArticles()` runs before the body is written, so the keys stay exact.
        self::assertSame([
            'status' => 'ok',
            'names' => ['articles', 'tag'],
            'reason' => null,
            'earlyExits' => [['line' => 15, 'bodyAssigned' => false, 'names' => null]],
        ], $methods['onGet']);
        self::assertSame(['id', 'title'], $methods['onPost']['names']);
        self::assertSame([
            ['line' => 29, 'bodyAssigned' => true, 'names' => ['message']],
            ['line' => 35, 'bodyAssigned' => false, 'names' => ['id']],
        ], $methods['onPost']['earlyExits']);
        // An `if` that does not return is still a branch the source cannot settle.
        self::assertSame('complex_control_flow', $methods['onPut']['reason']);
    }

    public function testFullAssignmentAfterEmbedIsReportedApartFromAnErrorBranch(): void
    {
        self::assertSame(
            [['line' => 16, 'earlyExit' => false, 'rels' => ['entries']]],
            $this->methods('page://self/index')['onGet']['embedReplacements'],
        );
        // Reading `$this->body` on the right keeps the embedded key.
        self::assertArrayNotHasKey('embedReplacements', $this->methods('page://self/index')['onPost']);
        self::assertSame(
            [['line' => 18, 'earlyExit' => true, 'rels' => ['_self']]],
            $this->methods('page://self/article')['onGet']['embedReplacements'],
        );

        $diagnostics = wait($this->handler()->diagnoseProject());
        $embed = array_values(array_filter(
            $diagnostics['data']['items'],
            static fn (array $item): bool => str_starts_with($item['code'], 'embed_body_'),
        ));
        self::assertSame([
            ['embed_body_replaced_on_early_exit', 'page://self/article#onGet', ['line' => 18, 'rels' => ['_self']]],
            ['embed_body_replaced', 'page://self/index#onGet', ['line' => 16, 'rels' => ['entries']]],
        ], array_map(static fn (array $item): array => [$item['code'], $item['subject'], $item['details']], $embed));
    }

    /** @return array<string, array<string, mixed>> */
    private function methods(string $uri): array
    {
        $response = wait($this->handler()->describeResource($uri));
        self::assertSame('ok', $response['status']);

        return array_column(
            array_map(
                static fn (array $method): array => ['name' => $method['name'], 'body' => $method['responseBody']],
                $response['data']['methods'],
            ),
            'body',
            'name',
        );
    }

    private function handler(): SemanticQueryHandler
    {
        return new SemanticQueryHandler(dirname(__DIR__, 3) . '/Fixture/BodyGuards');
    }
}
