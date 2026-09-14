<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Template;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Template\ResourceTemplateQuery;
use Suzumaze\BearPhpactor\Semantic\Template\ResourceTemplateResolution;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use PHPUnit\Framework\TestCase;

final class ResourceTemplateQueryTest extends TestCase
{
    private Project $project;
    private ResourceTemplateQuery $query;

    protected function setUp(): void
    {
        $project = Project::fromRoot(self::fixtureDir());
        self::assertNotNull($project);
        $this->project = $project;
        $this->query = new ResourceTemplateQuery();
    }

    public function testResolvesTwigTemplateForResource(): void
    {
        $result = $this->query->resolve($this->project, 'page://self/dashboard', 'twig');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceTemplateResolution::class, $result->value);
        self::assertSame('page://self/dashboard', $result->value->resource->uri->uri());
        self::assertSame(
            self::fixtureDir() . '/var/templates/Page/Dashboard.html.twig',
            $result->value->templateFile,
        );
    }

    public function testResolvesQiqTemplateForResource(): void
    {
        $result = $this->query->resolve($this->project, 'app://self/user', 'qiq');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceTemplateResolution::class, $result->value);
        self::assertSame(self::fixtureDir() . '/var/qiq/template/App/User.php', $result->value->templateFile);
    }

    public function testReturnsStructuredFailureStatuses(): void
    {
        self::assertSame(
            SemanticStatus::Unsupported,
            $this->query->resolve($this->project, 'app://self/user', 'blade')->status,
        );
        self::assertSame(
            SemanticStatus::InvalidInput,
            $this->query->resolve($this->project, 'not-a-resource-uri', 'twig')->status,
        );
        self::assertSame(
            SemanticStatus::NotFound,
            $this->query->resolve($this->project, 'app://self/missing', 'twig')->status,
        );
    }

    public function testPreservesAmbiguousResourceCandidatesEvenWithoutTemplates(): void
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/Router');
        self::assertNotFalse($fixture);
        $project = Project::fromRoot($fixture);
        self::assertNotNull($project);

        $result = $this->query->resolve($project, 'page://self/ambiguous', 'twig');

        self::assertSame(SemanticStatus::Ambiguous, $result->status);
        self::assertSame(
            [
                $fixture . '/lib/Resource/Page/Admin/Ambiguous.php',
                $fixture . '/lib/Resource/Page/Content/Ambiguous.php',
            ],
            array_map(
                static fn (ResourceTemplateResolution $candidate): string => $candidate->resource->file,
                $result->candidates,
            ),
        );
        self::assertSame(
            [null, null],
            array_map(
                static fn (ResourceTemplateResolution $candidate): ?string => $candidate->templateFile,
                $result->candidates,
            ),
        );
    }

    public function testResolvesThroughExplicitHeadlessWorkspace(): void
    {
        $workspace = WorkspaceContext::fromRoot(self::fixtureDir());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = $this->query->resolveInWorkspace(
            $workspace->value,
            'app://self/dashboard',
            'qiq',
            'src/Resource/App/Dashboard.php',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceTemplateResolution::class, $result->value);
        self::assertSame(self::fixtureDir() . '/var/qiq/template/App/Dashboard.php', $result->value->templateFile);
    }

    private static function fixtureDir(): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/Template/basic');
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
