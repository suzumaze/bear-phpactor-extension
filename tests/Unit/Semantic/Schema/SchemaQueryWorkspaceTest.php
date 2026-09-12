<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Schema;

use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use PHPUnit\Framework\TestCase;

final class SchemaQueryWorkspaceTest extends TestCase
{
    private string $temporaryRoot;
    private string $workspace;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/bear-schema-query-' . bin2hex(random_bytes(8));
        $this->workspace = $this->temporaryRoot . '/workspace';
        $outside = $this->temporaryRoot . '/outside';
        self::assertTrue(mkdir($this->workspace . '/src/Resource/App', 0777, true));
        self::assertTrue(mkdir($this->workspace . '/var/json_schema', 0777, true));
        self::assertTrue(mkdir($outside, 0777, true));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/composer.json',
            <<<'JSON'
{
    "autoload": {
        "psr-4": {
            "Acme\\App\\": "src/"
        }
    }
}
JSON,
        ));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/src/Resource/App/User.php',
            '<?php namespace Acme\\App\\Resource\\App; final class User {}',
        ));
        self::assertNotFalse(file_put_contents($outside . '/user.json', '{"type":"object"}'));
        self::assertTrue(symlink($outside . '/user.json', $this->workspace . '/var/json_schema/user.json'));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryRoot);
    }

    public function testRejectsNamedSchemaWhoseSymlinkTargetEscapesWorkspace(): void
    {
        $result = (new SchemaQuery())->resolveNamedInWorkspace(
            $this->context(),
            'user.json',
            SchemaQuery::KIND_RESPONSE,
        );

        self::assertSame(SemanticStatus::OutsideWorkspace, $result->status);
        self::assertNull($result->value);
    }

    public function testRejectsInvalidContextBeforeResolvingSchema(): void
    {
        $result = (new SchemaQuery())->resolveNamedInWorkspace(
            $this->context(),
            'user.json',
            SchemaQuery::KIND_RESPONSE,
            '../outside/user.json',
        );

        self::assertSame(SemanticStatus::InvalidInput, $result->status);
        self::assertNull($result->value);
    }

    private function context(): WorkspaceContext
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
