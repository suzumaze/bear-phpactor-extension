<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Schema;

use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaFacts;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use PHPUnit\Framework\TestCase;

final class SchemaFactsQueryTest extends TestCase
{
    private string $temporaryRoot;
    private string $workspace;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/bear-schema-facts-' . bin2hex(random_bytes(8));
        $this->workspace = $this->temporaryRoot . '/workspace';
        self::assertTrue(mkdir($this->workspace . '/src', 0777, true));
        self::assertTrue(mkdir($this->workspace . '/var/json_schema', 0777, true));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/composer.json',
            '{"autoload":{"psr-4":{"Acme\\\\App\\\\":"src/"}}}',
        ));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryRoot);
    }

    public function testDescribesSortedPropertiesRequiredFlagsAndTypes(): void
    {
        $workspace = WorkspaceContext::fromRoot(self::bodyFixture());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = (new SchemaFactsQuery())->describeNamedInWorkspace(
            $workspace->value,
            'user.json',
            SchemaQuery::KIND_RESPONSE,
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(SchemaFacts::class, $result->value);
        self::assertTrue($result->value->available);
        self::assertSame(['object'], $result->value->types);
        self::assertSame(
            [
                'email:optional:string',
                'id:required:integer',
                'name:required:string',
            ],
            array_map(
                static fn ($property): string => sprintf(
                    '%s:%s:%s',
                    $property->name,
                    $property->required ? 'required' : 'optional',
                    implode('|', $property->types),
                ),
                $result->value->properties,
            ),
        );
    }

    public function testPreservesNumericPropertyNamesAndSortsUnionTypes(): void
    {
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/var/json_schema/numeric.json',
            '{"type":["null","object","null"],"properties":{"0":{"type":["string","null"]}}}',
        ));

        $result = (new SchemaFactsQuery())->describeNamedInWorkspace(
            $this->workspace(),
            'numeric.json',
            SchemaQuery::KIND_RESPONSE,
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(SchemaFacts::class, $result->value);
        self::assertSame(['null', 'object'], $result->value->types);
        self::assertSame('0', $result->value->properties[0]->name);
        self::assertSame(['null', 'string'], $result->value->properties[0]->types);
    }

    public function testReportsMalformedOversizedAndOverlyDeepJsonAsParseError(): void
    {
        $workspace = WorkspaceContext::fromRoot(self::bodyFixture());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);
        self::assertSame(
            SemanticStatus::ParseError,
            (new SchemaFactsQuery())->describeNamedInWorkspace(
                $workspace->value,
                'broken.json',
                SchemaQuery::KIND_RESPONSE,
            )->status,
        );

        self::assertNotFalse(file_put_contents(
            $this->workspace . '/var/json_schema/large.json',
            '{"padding":"' . str_repeat('x', 1048577) . '"}',
        ));
        self::assertSame(
            SemanticStatus::ParseError,
            (new SchemaFactsQuery())->describeNamedInWorkspace(
                $this->workspace(),
                'large.json',
                SchemaQuery::KIND_RESPONSE,
            )->status,
        );

        self::assertNotFalse(file_put_contents(
            $this->workspace . '/var/json_schema/deep.json',
            str_repeat('{"x":', 65) . 'true' . str_repeat('}', 65),
        ));
        self::assertSame(
            SemanticStatus::ParseError,
            (new SchemaFactsQuery())->describeNamedInWorkspace(
                $this->workspace(),
                'deep.json',
                SchemaQuery::KIND_RESPONSE,
            )->status,
        );
    }

    public function testPreservesUnavailableAmbiguousResourceCandidates(): void
    {
        $workspace = WorkspaceContext::fromRoot(self::routerFixture());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = (new SchemaFactsQuery())->describeForResourceInWorkspace(
            $workspace->value,
            'page://self/ambiguous',
        );

        self::assertSame(SemanticStatus::Ambiguous, $result->status);
        self::assertCount(2, $result->candidates);
        self::assertFalse($result->candidates[0]->available);
        self::assertFalse($result->candidates[1]->available);
    }

    public function testCachesParsedDocumentAndInvalidatesSameSizeSameTimestampChange(): void
    {
        $file = $this->workspace . '/var/json_schema/cache.json';
        self::assertNotFalse(file_put_contents(
            $file,
            '{"type":"object","properties":{"value":{"type":"string"}}}',
        ));
        $modified = filemtime($file);
        self::assertIsInt($modified);
        $query = new SchemaFactsQuery();

        $first = $query->describeNamedInWorkspace(
            $this->workspace(),
            'cache.json',
            SchemaQuery::KIND_RESPONSE,
        );
        $second = $query->describeNamedInWorkspace(
            $this->workspace(),
            'cache.json',
            SchemaQuery::KIND_RESPONSE,
        );
        self::assertInstanceOf(SchemaFacts::class, $first->value);
        self::assertInstanceOf(SchemaFacts::class, $second->value);
        self::assertSame($first->value->properties[0], $second->value->properties[0]);

        self::assertNotFalse(file_put_contents(
            $file,
            '{"type":"object","properties":{"value":{"type":"number"}}}',
        ));
        self::assertTrue(touch($file, $modified));

        $third = $query->describeNamedInWorkspace(
            $this->workspace(),
            'cache.json',
            SchemaQuery::KIND_RESPONSE,
        );
        self::assertInstanceOf(SchemaFacts::class, $third->value);
        self::assertSame(['number'], $third->value->properties[0]->types);
        self::assertNotSame($first->value->properties[0], $third->value->properties[0]);
    }

    private function workspace(): WorkspaceContext
    {
        $result = WorkspaceContext::fromRoot($this->workspace);
        self::assertInstanceOf(WorkspaceContext::class, $result->value);

        return $result->value;
    }

    private static function bodyFixture(): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/Body/basic');
        self::assertNotFalse($fixture);

        return $fixture;
    }

    private static function routerFixture(): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/Router');
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
