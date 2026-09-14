<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Alps;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsDescriptorReferences;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsDescriptorReferencesQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

final class AlpsDescriptorReferencesQueryTest extends TestCase
{
    public function testFindsOnlyAttributesResolvingToTheSameDescriptor(): void
    {
        $result = (new AlpsDescriptorReferencesQuery())->findInWorkspace(
            $this->workspace(),
            'doDeleteArticle',
            'src/AlpsReferences.php',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(AlpsDescriptorReferences::class, $result->value);
        self::assertSame(
            self::fixtureDir() . '/var/alps/profile.json',
            $result->value->descriptor->profileFile,
        );
        self::assertSame(
            ['doDeleteArticle', 'doDeleteArticle'],
            array_column($result->value->references, 'descriptorId'),
        );

        $starts = [];
        foreach ($result->value->references as $reference) {
            self::assertSame(self::fixtureDir() . '/src/AlpsReferences.php', $reference->sourceFile);
            $source = (string) file_get_contents($reference->sourceFile);
            self::assertSame(
                'doDeleteArticle',
                substr($source, $reference->contentStart, $reference->contentEnd - $reference->contentStart),
            );
            $starts[] = $reference->contentStart;
        }
        $sorted = $starts;
        sort($sorted);
        self::assertSame($sorted, $starts);
    }

    public function testMissingAndInvalidDescriptorsReturnNoReferencePayload(): void
    {
        $query = new AlpsDescriptorReferencesQuery();
        $missing = $query->findInWorkspace($this->workspace(), 'missing', 'src/AlpsReferences.php');
        $invalid = $query->findInWorkspace($this->workspace(), '', 'src/AlpsReferences.php');

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
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/Alps/App1');
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
