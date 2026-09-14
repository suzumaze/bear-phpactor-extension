<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Schema;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaQuery;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaResolution;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use PHPUnit\Framework\TestCase;

final class SchemaQueryTest extends TestCase
{
    private Project $project;
    private SchemaQuery $query;

    protected function setUp(): void
    {
        $project = Project::fromRoot(self::fixtureDir());
        self::assertNotNull($project);
        $this->project = $project;
        $this->query = new SchemaQuery();
    }

    public function testResolvesNamedResponseAndRequestSchemas(): void
    {
        $response = $this->query->resolveNamed($this->project, 'user.json', SchemaQuery::KIND_RESPONSE);
        $request = $this->query->resolveNamed($this->project, 'user-params.json', SchemaQuery::KIND_REQUEST);

        self::assertSame(SemanticStatus::Ok, $response->status);
        self::assertInstanceOf(SchemaResolution::class, $response->value);
        self::assertSame(SchemaQuery::SOURCE_ATTRIBUTE, $response->value->source);
        self::assertSame(self::fixtureDir() . '/var/json_schema/user.json', $response->value->file);

        self::assertSame(SemanticStatus::Ok, $request->status);
        self::assertInstanceOf(SchemaResolution::class, $request->value);
        self::assertSame(SchemaQuery::KIND_REQUEST, $request->value->kind);
        self::assertSame(self::fixtureDir() . '/var/json_validate/user-params.json', $request->value->file);
    }

    public function testResolvesResponseSchemaFromResourceConvention(): void
    {
        $result = $this->query->resolveForResource($this->project, 'app://self/bodyTypeDemo');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(SchemaResolution::class, $result->value);
        self::assertSame(SchemaQuery::SOURCE_CONVENTION, $result->value->source);
        self::assertSame('app://self/bodyTypeDemo', $result->value->resource?->uri->uri());
        self::assertSame(self::fixtureDir() . '/var/json_schema/body-type-demo.json', $result->value->file);
    }

    public function testUsesDocumentedConventionPriority(): void
    {
        $result = $this->query->resolveForResource($this->project, 'app://self/cache/articlePreview');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(SchemaResolution::class, $result->value);
        self::assertSame(self::fixtureDir() . '/var/json_schema/cache/article-preview.json', $result->value->file);
    }

    public function testReturnsStructuredFailureStatuses(): void
    {
        foreach (['', '../escape.json', '/absolute.json', 'windows\\escape.json', "nul\0.json"] as $name) {
            self::assertSame(
                SemanticStatus::InvalidInput,
                $this->query->resolveNamed($this->project, $name, SchemaQuery::KIND_RESPONSE)->status,
            );
        }
        self::assertSame(
            SemanticStatus::Unsupported,
            $this->query->resolveNamed($this->project, 'user.json', 'parameters')->status,
        );
        self::assertSame(
            SemanticStatus::NotFound,
            $this->query->resolveNamed($this->project, 'missing.json', SchemaQuery::KIND_RESPONSE)->status,
        );
        self::assertSame(
            SemanticStatus::Unsupported,
            $this->query->resolveForResource(
                $this->project,
                'app://self/user',
                SchemaQuery::KIND_REQUEST,
            )->status,
        );
    }

    public function testDoesNotMistakeTwoDotsInsideFilenameForTraversal(): void
    {
        $result = $this->query->resolveNamed(
            $this->project,
            'schema..v2.json',
            SchemaQuery::KIND_RESPONSE,
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(SchemaResolution::class, $result->value);
        self::assertSame(self::fixtureDir() . '/var/json_schema/schema..v2.json', $result->value->file);
    }

    public function testPreservesAmbiguousResourceCandidatesWithoutSchemas(): void
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/Router');
        self::assertNotFalse($fixture);
        $project = Project::fromRoot($fixture);
        self::assertNotNull($project);

        $result = $this->query->resolveForResource($project, 'page://self/ambiguous');

        self::assertSame(SemanticStatus::Ambiguous, $result->status);
        self::assertSame(
            [
                $fixture . '/lib/Resource/Page/Admin/Ambiguous.php',
                $fixture . '/lib/Resource/Page/Content/Ambiguous.php',
            ],
            array_map(
                static fn (SchemaResolution $candidate): string => $candidate->resource->file,
                $result->candidates,
            ),
        );
        self::assertSame(
            [null, null],
            array_map(static fn (SchemaResolution $candidate): ?string => $candidate->file, $result->candidates),
        );
    }

    public function testResolvesThroughExplicitHeadlessWorkspace(): void
    {
        $workspace = WorkspaceContext::fromRoot(self::fixtureDir());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = $this->query->resolveForResourceInWorkspace(
            $workspace->value,
            'page://self/admin/userProfile',
            contextPath: 'src/Resource/Page/Admin/UserProfile.php',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(SchemaResolution::class, $result->value);
        self::assertSame(self::fixtureDir() . '/var/json_schema/admin/user-profile.json', $result->value->file);
    }

    private static function fixtureDir(): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/JsonSchema/basic');
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
