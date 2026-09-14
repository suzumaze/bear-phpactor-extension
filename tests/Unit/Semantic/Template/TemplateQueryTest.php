<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Template;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Template\TemplateQuery;
use Suzumaze\BearPhpactor\Semantic\Template\TemplateResolution;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Template\TemplateReference;
use PHPUnit\Framework\TestCase;

final class TemplateQueryTest extends TestCase
{
    private Project $project;
    private TemplateQuery $query;

    protected function setUp(): void
    {
        $project = Project::fromRoot(self::fixtureDir());
        self::assertNotNull($project);
        $this->project = $project;
        $this->query = new TemplateQuery();
    }

    public function testResolvesTwigNameWithoutLspPosition(): void
    {
        $result = $this->query->resolve(
            $this->project,
            TemplateReference::ENGINE_TWIG,
            'element/component/card.html.twig',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(TemplateResolution::class, $result->value);
        self::assertSame('twig', $result->value->engine);
        self::assertSame('element/component/card.html.twig', $result->value->name);
        self::assertSame(
            self::fixtureDir() . '/var/templates/element/component/card.html.twig',
            $result->value->file,
        );
    }

    public function testResolvesQiqRelativeNameWithDocumentContext(): void
    {
        $result = $this->query->resolve(
            $this->project,
            TemplateReference::ENGINE_QIQ,
            '../parent',
            self::fixtureDir() . '/var/qiq/template/Page/Nested/RelativeReferences.php',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(TemplateResolution::class, $result->value);
        self::assertSame(self::fixtureDir() . '/var/qiq/template/Page/parent.php', $result->value->file);
    }

    public function testReturnsStructuredFailureStatuses(): void
    {
        self::assertSame(
            SemanticStatus::Unsupported,
            $this->query->resolve($this->project, 'blade', 'view')->status,
        );
        self::assertSame(
            SemanticStatus::Unsupported,
            $this->query->resolve($this->project, 'twig', '@bundle/view.html.twig')->status,
        );
        self::assertSame(
            SemanticStatus::InvalidInput,
            $this->query->resolve($this->project, 'twig', '../../outside.html.twig')->status,
        );
        self::assertSame(
            SemanticStatus::InvalidInput,
            $this->query->resolve($this->project, 'qiq', '../parent')->status,
        );
        self::assertSame(
            SemanticStatus::NotFound,
            $this->query->resolve($this->project, 'twig', 'missing.html.twig')->status,
        );
    }

    public function testResolvesThroughExplicitHeadlessWorkspace(): void
    {
        $workspace = WorkspaceContext::fromRoot(self::fixtureDir());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = $this->query->resolveInWorkspace(
            $workspace->value,
            'qiq',
            './sibling',
            'var/qiq/template/Page/Nested/RelativeReferences.php',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(TemplateResolution::class, $result->value);
        self::assertSame(self::fixtureDir() . '/var/qiq/template/Page/Nested/sibling.php', $result->value->file);
    }

    private static function fixtureDir(): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/Template');
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
