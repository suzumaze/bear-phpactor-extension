<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Alps;

use Suzumaze\BearPhpactor\Semantic\Alps\AlpsDescriptorFacts;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsDescriptorRelationFact;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use PHPUnit\Framework\TestCase;

final class AlpsFactsQueryTest extends TestCase
{
    public function testDescribesDescriptorFieldsAndExplicitRelationships(): void
    {
        $workspace = $this->workspace();
        $result = (new AlpsFactsQuery())->describeInWorkspace(
            $workspace,
            'goArticle',
            'src/Resource/App/AlpsDemo.php',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(AlpsDescriptorFacts::class, $result->value);
        self::assertSame('goArticle', $result->value->descriptor->resolution->descriptorId);
        self::assertSame('safe', $result->value->descriptor->type);
        self::assertSame('#Article', $result->value->descriptor->rt);
        self::assertSame('View Article', $result->value->descriptor->title);

        self::assertCount(1, $result->value->outgoingRelations);
        self::assertRelation(
            $result->value->outgoingRelations[0],
            AlpsDescriptorRelationFact::KIND_RT,
            'goArticle',
            'Article',
            SemanticStatus::Ok,
        );

        self::assertCount(1, $result->value->incomingRelations);
        self::assertRelation(
            $result->value->incomingRelations[0],
            AlpsDescriptorRelationFact::KIND_HREF,
            'Article',
            'goArticle',
            SemanticStatus::Ok,
        );
    }

    public function testDefaultsDefinitionTypeAndReportsUnresolvedLocalTarget(): void
    {
        $query = new AlpsFactsQuery();
        $workspace = $this->workspace();

        $article = $query->describeInWorkspace($workspace, 'Article');
        self::assertInstanceOf(AlpsDescriptorFacts::class, $article->value);
        self::assertSame('semantic', $article->value->descriptor->type);
        self::assertSame(
            ['doDeleteArticle', 'goArticle'],
            array_map(
                static fn (AlpsDescriptorRelationFact $relation): string => $relation->targetId,
                $article->value->outgoingRelations,
            ),
        );

        $delete = $query->describeInWorkspace($workspace, 'doDeleteArticle');
        self::assertInstanceOf(AlpsDescriptorFacts::class, $delete->value);
        self::assertCount(1, $delete->value->outgoingRelations);
        self::assertRelation(
            $delete->value->outgoingRelations[0],
            AlpsDescriptorRelationFact::KIND_RT,
            'doDeleteArticle',
            'ArticleList',
            SemanticStatus::NotFound,
        );
        self::assertNull($delete->value->outgoingRelations[0]->targetOffset);
    }

    public function testReturnsStructuredFailures(): void
    {
        $query = new AlpsFactsQuery();
        $workspace = $this->workspace();

        self::assertSame(SemanticStatus::InvalidInput, $query->describeInWorkspace($workspace, '')->status);
        self::assertSame(SemanticStatus::NotFound, $query->describeInWorkspace($workspace, 'missing')->status);
        self::assertSame(
            SemanticStatus::InvalidInput,
            $query->describeInWorkspace($workspace, 'Article', '../outside.php')->status,
        );
    }

    public function testKeepsNestedAndAmbiguousRelationshipsDeterministic(): void
    {
        $temporaryRoot = sys_get_temp_dir() . '/bear-alps-facts-' . bin2hex(random_bytes(8));
        try {
            self::assertTrue(mkdir($temporaryRoot . '/var/alps', 0777, true));
            self::assertNotFalse(file_put_contents(
                $temporaryRoot . '/composer.json',
                '{"autoload":{"psr-4":{"Acme\\\\App\\\\":"src/"}}}',
            ));
            self::assertNotFalse(file_put_contents(
                $temporaryRoot . '/apidoc.xml',
                '<apidoc><alps>var/alps/profile.json</alps></apidoc>',
            ));
            self::assertNotFalse(file_put_contents(
                $temporaryRoot . '/var/alps/profile.json',
                '{"alps":{"descriptor":['
                    . '{"id":"parent","descriptor":{"id":"child"}},'
                    . '{"id":"target"},{"id":"target"},'
                    . '{"id":"source","rt":"#target","href":"https://example.test/profile#external"}'
                    . ']}}',
            ));
            $workspaceResult = WorkspaceContext::fromRoot($temporaryRoot);
            self::assertInstanceOf(WorkspaceContext::class, $workspaceResult->value);
            $query = new AlpsFactsQuery();

            $child = $query->describeInWorkspace($workspaceResult->value, 'child');
            self::assertInstanceOf(AlpsDescriptorFacts::class, $child->value);
            self::assertCount(1, $child->value->incomingRelations);
            self::assertRelation(
                $child->value->incomingRelations[0],
                AlpsDescriptorRelationFact::KIND_CONTAINS,
                'parent',
                'child',
                SemanticStatus::Ok,
            );

            $source = $query->describeInWorkspace($workspaceResult->value, 'source');
            self::assertInstanceOf(AlpsDescriptorFacts::class, $source->value);
            self::assertCount(1, $source->value->outgoingRelations);
            self::assertRelation(
                $source->value->outgoingRelations[0],
                AlpsDescriptorRelationFact::KIND_RT,
                'source',
                'target',
                SemanticStatus::Ambiguous,
            );
            self::assertNull($source->value->outgoingRelations[0]->targetOffset);

            $target = $query->describeInWorkspace($workspaceResult->value, 'target');
            self::assertSame(SemanticStatus::Ambiguous, $target->status);
            self::assertCount(2, $target->candidates);
            self::assertSame(
                [73, 89],
                array_map(
                    static fn (AlpsDescriptorFacts $facts): int => $facts->descriptor->resolution->offset,
                    $target->candidates,
                ),
            );
        } finally {
            $this->removeTree($temporaryRoot);
        }
    }

    private static function assertRelation(
        AlpsDescriptorRelationFact $relation,
        string $kind,
        string $sourceId,
        string $targetId,
        SemanticStatus $targetStatus,
    ): void {
        self::assertSame($kind, $relation->kind);
        self::assertSame($sourceId, $relation->sourceId);
        self::assertSame($targetId, $relation->targetId);
        self::assertSame($targetStatus, $relation->targetStatus);
    }

    private function workspace(): WorkspaceContext
    {
        $workspace = WorkspaceContext::fromRoot(self::fixture());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        return $workspace->value;
    }

    private static function fixture(): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/Alps/App1');
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
