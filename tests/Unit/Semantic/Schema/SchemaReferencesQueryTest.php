<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Schema;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaQuery;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaReferences;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaReferencesQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

final class SchemaReferencesQueryTest extends TestCase
{
    public function testFindsOnlyExplicitReferencesToTheSameResponseSchema(): void
    {
        $result = (new SchemaReferencesQuery())->findNamedInWorkspace(
            $this->workspace(),
            'user.json',
            SchemaQuery::KIND_RESPONSE,
            'src/SchemaReferences.php',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(SchemaReferences::class, $result->value);
        self::assertSame(self::fixtureDir() . '/var/json_schema/user.json', $result->value->schema->file);
        self::assertSame(['user.json', 'user.json'], array_column($result->value->references, 'fileName'));
        self::assertSame(
            [SchemaQuery::KIND_RESPONSE, SchemaQuery::KIND_RESPONSE],
            array_column($result->value->references, 'kind'),
        );

        $starts = [];
        foreach ($result->value->references as $reference) {
            self::assertSame(self::fixtureDir() . '/src/SchemaReferences.php', $reference->sourceFile);
            $source = (string) file_get_contents($reference->sourceFile);
            self::assertSame(
                'user.json',
                substr($source, $reference->contentStart, $reference->contentEnd - $reference->contentStart),
            );
            $starts[] = $reference->contentStart;
        }
        $sorted = $starts;
        sort($sorted);
        self::assertSame($sorted, $starts);
    }

    public function testKeepsRequestAndResponseSchemaIdentitiesSeparate(): void
    {
        $result = (new SchemaReferencesQuery())->findNamedInWorkspace(
            $this->workspace(),
            'user-params.json',
            SchemaQuery::KIND_REQUEST,
            'src/SchemaReferences.php',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(SchemaReferences::class, $result->value);
        self::assertCount(1, $result->value->references);
        self::assertSame(SchemaQuery::KIND_REQUEST, $result->value->references[0]->kind);
    }

    public function testMissingAndInvalidSchemasReturnNoReferencePayload(): void
    {
        $query = new SchemaReferencesQuery();
        $missing = $query->findNamedInWorkspace(
            $this->workspace(),
            'missing.json',
            SchemaQuery::KIND_RESPONSE,
            'src/SchemaReferences.php',
        );
        $invalid = $query->findNamedInWorkspace(
            $this->workspace(),
            '../user.json',
            SchemaQuery::KIND_RESPONSE,
            'src/SchemaReferences.php',
        );

        self::assertSame(SemanticStatus::NotFound, $missing->status);
        self::assertNull($missing->value);
        self::assertSame(SemanticStatus::InvalidInput, $invalid->status);
        self::assertNull($invalid->value);
    }

    private function workspace(): WorkspaceContext
    {
        $workspace = WorkspaceContext::fromRoot(self::fixtureDir());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        return $workspace->value;
    }

    private static function fixtureDir(): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/JsonSchema/basic');
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
