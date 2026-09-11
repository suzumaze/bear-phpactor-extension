<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Alps;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsDescriptorResolution;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use PHPUnit\Framework\TestCase;

final class AlpsQueryTest extends TestCase
{
    private AlpsQuery $query;

    protected function setUp(): void
    {
        $this->query = new AlpsQuery();
    }

    public function testResolvesDescriptorWithoutLspPosition(): void
    {
        $result = $this->query->resolve($this->project('App1'), 'doDeleteArticle');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(AlpsDescriptorResolution::class, $result->value);
        self::assertSame('doDeleteArticle', $result->value->descriptorId);
        self::assertSame(self::fixture('App1') . '/var/alps/profile.json', $result->value->profileFile);
        self::assertSame(425, $result->value->offset);
    }

    public function testReturnsStructuredFailureStatuses(): void
    {
        self::assertSame(SemanticStatus::InvalidInput, $this->query->resolve($this->project('App1'), '')->status);
        self::assertSame(
            SemanticStatus::NotFound,
            $this->query->resolve($this->project('App1'), 'missingDescriptor')->status,
        );
        self::assertSame(
            SemanticStatus::NotFound,
            $this->query->resolve($this->project('NoApidoc'), 'doDeleteArticle')->status,
        );
        self::assertSame(
            SemanticStatus::OutsideWorkspace,
            $this->query->resolve($this->project('EscapePath'), 'doDeleteArticle')->status,
        );
        self::assertSame(
            SemanticStatus::OutsideWorkspace,
            $this->query->resolve($this->project('AbsolutePath'), 'doDeleteArticle')->status,
        );
    }

    public function testResolvesThroughExplicitHeadlessWorkspace(): void
    {
        $workspace = WorkspaceContext::fromRoot(self::fixture('App1'));
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = $this->query->resolveInWorkspace(
            $workspace->value,
            'goArticle',
            'src/Resource/App/AlpsDemo.php',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(AlpsDescriptorResolution::class, $result->value);
        self::assertSame(self::fixture('App1') . '/var/alps/profile.json', $result->value->profileFile);
    }

    private function project(string $name): Project
    {
        $project = Project::fromRoot(self::fixture($name));
        self::assertNotNull($project);

        return $project;
    }

    private static function fixture(string $name): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/Alps/' . $name);
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
