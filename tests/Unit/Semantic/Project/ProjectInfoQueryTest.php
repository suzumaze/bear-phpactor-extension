<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Project;

use Suzumaze\BearPhpactor\Semantic\Project\ProjectInfo;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectInfoQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use PHPUnit\Framework\TestCase;

final class ProjectInfoQueryTest extends TestCase
{
    private string $temporaryRoot;
    private string $workspace;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/bear-project-info-' . bin2hex(random_bytes(8));
        $this->workspace = $this->temporaryRoot . '/workspace';
        self::assertTrue(mkdir($this->workspace . '/src/Resource/App', 0777, true));
        self::assertTrue(mkdir($this->temporaryRoot . '/outside', 0777, true));
        self::assertTrue(symlink($this->temporaryRoot . '/outside', $this->workspace . '/escape'));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/composer.json',
            json_encode([
                'autoload' => [
                    'psr-4' => [
                        'Acme\\App\\' => 'src/',
                        'Acme\\Escape\\' => 'escape/',
                        'Acme\\Missing\\' => 'missing/',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/src/Resource/App/User.php',
            '<?php final class User extends \\BEAR\\Resource\\ResourceObject {}',
        ));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryRoot);
    }

    public function testDescribesSafeProjectInventoryAndKnownCompatibilityIssue(): void
    {
        $query = new ProjectInfoQuery([
            'phpactor/language-server' => '7.0.1',
            'phpactor/language-server-protocol' => '3.17.5',
            'suzumaze/bear-phpactor-extension' => 'dev-test',
        ]);

        $result = $query->describeInWorkspace($this->workspace());

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ProjectInfo::class, $result->value);
        self::assertSame('workspace', $result->value->workspaceName);
        self::assertSame('.', $result->value->projectPath);
        self::assertSame('composer.json', $result->value->composerPath);
        self::assertSame(['Acme\App\:src'], array_map(
            static fn ($root): string => $root->namespace . ':' . $root->path,
            $result->value->psr4Roots,
        ));
        self::assertSame(2, $result->value->excludedPsr4Roots);
        self::assertSame(1, $result->value->resourceCount);
        self::assertSame([
            'alpsDescriptorFacts',
            'alpsDescriptorResolution',
            'contractComparison',
            'incomingResourceRelations',
            'projectInfo',
            'resourceAttributeFacts',
            'resourceAttributeInventory',
            'resourceDescription',
            'resourceInventory',
            'resourceReferences',
            'resourceResolution',
            'routeResolution',
            'schemaFacts',
            'schemaResolution',
            'sqlResolution',
            'templateResolution',
        ], $result->value->capabilities);
        self::assertSame('phpactor_did_change_protocol_conflict', $result->value->compatibilityIssues[0]->code);
        self::assertStringNotContainsString(
            $this->temporaryRoot,
            json_encode($result->value, JSON_THROW_ON_ERROR),
        );
    }

    public function testReturnsNoIssueForUnknownDevelopmentVersions(): void
    {
        $query = new ProjectInfoQuery([
            'phpactor/language-server' => 'dev-main',
            'phpactor/language-server-protocol' => '3.17.5',
        ]);

        $result = $query->describeInWorkspace($this->workspace());

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ProjectInfo::class, $result->value);
        self::assertSame([], $result->value->compatibilityIssues);
    }

    public function testRejectsContextOutsideWorkspace(): void
    {
        $result = (new ProjectInfoQuery())->describeInWorkspace($this->workspace(), '../outside');

        self::assertSame(SemanticStatus::InvalidInput, $result->status);
    }

    private function workspace(): WorkspaceContext
    {
        $result = WorkspaceContext::fromRoot($this->workspace);
        self::assertInstanceOf(WorkspaceContext::class, $result->value);

        return $result->value;
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
