<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Sql;

use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Sql\SqlQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use PHPUnit\Framework\TestCase;

final class SqlQueryWorkspaceTest extends TestCase
{
    private string $temporaryRoot;
    private string $workspace;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/bear-sql-query-' . bin2hex(random_bytes(8));
        $this->workspace = $this->temporaryRoot . '/workspace';
        $outside = $this->temporaryRoot . '/outside';
        self::assertTrue(mkdir($this->workspace . '/src', 0777, true));
        self::assertTrue(mkdir($this->workspace . '/var/db/sql', 0777, true));
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
        self::assertNotFalse(file_put_contents($this->workspace . '/src/Client.php', '<?php'));
        self::assertNotFalse(file_put_contents($outside . '/escape.sql', 'SELECT 1;'));
        self::assertTrue(symlink($outside . '/escape.sql', $this->workspace . '/var/db/sql/escape.sql'));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryRoot);
    }

    public function testRejectsSqlFileWhoseSymlinkTargetEscapesWorkspace(): void
    {
        $result = (new SqlQuery())->resolveInWorkspace($this->context(), 'escape', 'src/Client.php');

        self::assertSame(SemanticStatus::OutsideWorkspace, $result->status);
        self::assertNull($result->value);
    }

    public function testRejectsInvalidContextBeforeResolvingSqlId(): void
    {
        $result = (new SqlQuery())->resolveInWorkspace($this->context(), 'escape', '../outside/escape.sql');

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
