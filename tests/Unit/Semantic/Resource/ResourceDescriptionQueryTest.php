<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Resource;

use Suzumaze\BearPhpactor\Semantic\Resource\ResourceDescription;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceDescriptionQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use PHPUnit\Framework\TestCase;

final class ResourceDescriptionQueryTest extends TestCase
{
    public function testCombinesResourceFactsWithIncomingRelations(): void
    {
        $workspace = WorkspaceContext::fromRoot(self::templateFixture());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = (new ResourceDescriptionQuery())->describeInWorkspace(
            $workspace->value,
            'app://self/user',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceDescription::class, $result->value);
        self::assertSame('app://self/user', $result->value->facts->resource->uri->uri());
        self::assertTrue($result->value->incomingRelations->available);
        self::assertSame(3, $result->value->incomingRelations->total);
        self::assertSame(
            ['user', 'relativeUser', 'duplicate'],
            array_map(static fn ($relation): string => $relation->rel, $result->value->incomingRelations->relations),
        );
    }

    public function testDoesNotClaimIncomingRelationsForAmbiguousResource(): void
    {
        $workspace = WorkspaceContext::fromRoot(self::resourceFixture());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = (new ResourceDescriptionQuery())->describeInWorkspace($workspace->value, 'page://self/x');

        self::assertSame(SemanticStatus::Ambiguous, $result->status);
        self::assertCount(2, $result->candidates);
        self::assertFalse($result->candidates[0]->incomingRelations->available);
        self::assertFalse($result->candidates[1]->incomingRelations->available);
    }

    private static function templateFixture(): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/Template/basic');
        self::assertNotFalse($fixture);

        return $fixture;
    }

    private static function resourceFixture(): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/Resource');
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
