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
        // A guard condition runs on the main path; one that changes the body cannot be a guard.
        self::assertSame('complex_control_flow', $methods['onPatch']['reason']);
    }

    public function testControlFlowThatCannotChangeOrLeaveTheBodyIsSteppedOver(): void
    {
        $methods = $this->methods('app://self/steps');

        // A loop, a `try` and a `switch` that only build local values, before and after the assignment.
        self::assertSame(['status' => 'ok', 'names' => ['list', 'note'], 'reason' => null], $methods['onGet']);
        // A `return` inside a closure in the loop stays inside the closure.
        self::assertSame(['doubled'], $methods['onPost']['names']);
        // A `return` or `throw` in the block, or a `$this` method call, keeps the method unsupported.
        self::assertSame('complex_control_flow', $methods['onPut']['reason']);
        self::assertSame('complex_control_flow', $methods['onPatch']['reason']);
        self::assertSame('complex_control_flow', $methods['onDelete']['reason']);
    }

    public function testReplacementsIgnoreOtherObjectsAndDeferredCodeAndKnowEveryWayOut(): void
    {
        $methods = $this->methods('page://self/variants');
        $replacements = static fn (string $name): array => array_map(
            static fn (array $replacement): array => [$replacement['line'], $replacement['earlyExit']],
            $methods[$name]['embedReplacements'] ?? [],
        );

        // Anonymous class, closure and arrow function bodies are not this Resource's body on the main path.
        self::assertSame([], $replacements('onGet'));
        // else block ending in return, block ending in throw, alternative syntax: all leave the method.
        self::assertSame([[41, true]], $replacements('onPost'));
        self::assertSame([[53, true]], $replacements('onPut'));
        self::assertSame([[64, true]], $replacements('onPatch'));
        // A loop falls through to the end of the method.
        self::assertSame([[76, false]], $replacements('onDelete'));
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
            ['embed_body_replaced_on_early_exit', 'page://self/article#onGet', 18],
            ['embed_body_replaced', 'page://self/index#onGet', 16],
            ['embed_body_replaced', 'page://self/variants#onDelete', 76],
            ['embed_body_replaced_on_early_exit', 'page://self/variants#onPatch', 64],
            ['embed_body_replaced_on_early_exit', 'page://self/variants#onPost', 41],
            ['embed_body_replaced_on_early_exit', 'page://self/variants#onPut', 53],
        ], array_map(
            static fn (array $item): array => [$item['code'], $item['subject'], $item['details']['line']],
            $embed,
        ));
        self::assertSame(['line' => 18, 'rels' => ['_self']], $embed[0]['details']);
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
