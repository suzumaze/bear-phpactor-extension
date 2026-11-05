<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Resource;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;

use function Amp\Promise\wait;

/**
 * `@property array{...} $body` in the Resource class docblock, and which declared variant each proven path fits.
 */
final class BodyDocblockTest extends TestCase
{
    public function testUnionVariantsAreMatchedToTheGuardAndToTheEndOfTheMethod(): void
    {
        $data = $this->describe('page://self/category');

        self::assertSame([
            'source' => 'docblock',
            'tag' => '@property',
            'line' => 13,
            'status' => 'ok',
            'reason' => null,
            'variants' => [
                ['keys' => [['name' => 'message', 'type' => 'string', 'optional' => false]]],
                ['keys' => [
                    ['name' => 'category', 'type' => 'CategoryEntity', 'optional' => false],
                    ['name' => 'articles', 'type' => 'list<array{id: int, title: string}>', 'optional' => false],
                    ['name' => 'next', 'type' => 'string|null', 'optional' => true],
                ]],
            ],
            'typesResolved' => false,
        ], $data['declaredBody']);
        $body = $data['methods'][0]['responseBody'];
        // `next` is optional, so the end of the method fits the second variant without it.
        self::assertSame(1, $body['declaredVariant']);
        self::assertSame(0, $body['earlyExits'][0]['declaredVariant']);
        // The proven names stay what the code shows; the docblock never adds to them.
        self::assertSame(['articles', 'category'], $body['names']);
    }

    public function testAPathTheDocblockDoesNotDescribeHasNoVariant(): void
    {
        $data = $this->describe('page://self/stale');

        // PHPStan reads its own tag before the plain `@property array<string, mixed>`.
        self::assertSame(['@phpstan-property', 11], [$data['declaredBody']['tag'], $data['declaredBody']['line']]);
        self::assertSame(
            ['onGet' => null, 'onPost' => 0],
            $this->variants($data),
        );
    }

    public function testOpenShapesNullableTypesAndNonShapeMembers(): void
    {
        $open = $this->describe('page://self/open');
        self::assertSame(
            [['keys' => [['name' => 'page-title', 'type' => 'string', 'optional' => false]], 'open' => true]],
            $open['declaredBody']['variants'],
        );
        self::assertSame(['onGet' => 0], $this->variants($open));

        $nullable = $this->describe('page://self/nullable');
        self::assertSame(
            [['keys' => [['name' => 'count', 'type' => '?int', 'optional' => false]]], ['type' => 'null']],
            $nullable['declaredBody']['variants'],
        );
        // An empty body is neither `array{count: ?int}` nor null.
        self::assertSame(['onDelete' => null, 'onGet' => 0], $this->variants($nullable));

        $listing = $this->describe('page://self/listing');
        self::assertSame([['type' => 'list<string>'], ['keys' => []]], $listing['declaredBody']['variants']);
        self::assertSame(['onGet' => 1], $this->variants($listing));
    }

    public function testUnreadableOrMissingDeclarationsAddNoVariant(): void
    {
        $broken = $this->describe('page://self/broken');
        self::assertSame(
            ['status' => 'unsupported', 'reason' => 'unreadable_docblock', 'variants' => []],
            array_intersect_key($broken['declaredBody'], ['status' => 0, 'reason' => 0, 'variants' => 0]),
        );
        self::assertArrayNotHasKey('declaredVariant', $broken['methods'][0]['responseBody']);

        $plain = $this->describe('page://self/plain');
        self::assertArrayNotHasKey('declaredBody', $plain);
        self::assertArrayNotHasKey('declaredVariant', $plain['methods'][0]['responseBody']);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, int|null>
     */
    private function variants(array $data): array
    {
        $variants = [];
        foreach ($data['methods'] as $method) {
            $variants[$method['name']] = $method['responseBody']['declaredVariant'];
        }

        return $variants;
    }

    /** @return array<string, mixed> */
    private function describe(string $uri): array
    {
        $response = wait((new SemanticQueryHandler(dirname(__DIR__, 3) . '/Fixture/BodyDocblock'))
            ->describeResource($uri));
        self::assertSame('ok', $response['status']);

        return $response['data'];
    }
}
