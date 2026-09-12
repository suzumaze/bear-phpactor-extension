<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Resource;

use Suzumaze\BearPhpactor\Semantic\Resource\ResourceInventory;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceInventoryQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use PHPUnit\Framework\TestCase;

final class ResourceInventoryQueryTest extends TestCase
{
    private string $temporaryRoot;
    private string $workspace;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/bear-resource-inventory-' . bin2hex(random_bytes(8));
        $this->workspace = $this->temporaryRoot . '/workspace';
        self::assertTrue(mkdir($this->workspace . '/one/Resource/App', 0777, true));
        self::assertTrue(mkdir($this->workspace . '/two/Resource/App', 0777, true));
        self::assertTrue(mkdir($this->workspace . '/one/Resource/Page', 0777, true));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/composer.json',
            <<<'JSON'
{
    "autoload": {
        "psr-4": {
            "Acme\\One\\": "one/",
            "Acme\\Two\\": "two/"
        }
    }
}
JSON,
        ));
        $this->writeResource('one/Resource/App/User.php');
        $this->writeResource('two/Resource/App/User.php');
        $this->writeResource('one/Resource/App/UserProfile.php');
        $this->writeResource('one/Resource/Page/Index.php');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryRoot);
    }

    public function testListsDuplicateUrisWithoutChoosingOne(): void
    {
        $result = (new ResourceInventoryQuery())->listInWorkspace($this->workspace(), 'app', 'user');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceInventory::class, $result->value);
        self::assertSame(3, $result->value->total);
        self::assertFalse($result->value->truncated);
        self::assertSame(
            ['app://self/user', 'app://self/user', 'app://self/userProfile'],
            array_map(static fn ($resource): string => $resource->uri->uri(), $result->value->resources),
        );
        self::assertSame(
            ['one/Resource/App/User.php', 'two/Resource/App/User.php', 'one/Resource/App/UserProfile.php'],
            array_map(
                fn ($resource): string => $this->relative($resource->file),
                $result->value->resources,
            ),
        );
    }

    public function testAppliesLimitAndReportsTruncation(): void
    {
        $result = (new ResourceInventoryQuery())->listInWorkspace($this->workspace(), limit: 2);

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceInventory::class, $result->value);
        self::assertSame(4, $result->value->total);
        self::assertCount(2, $result->value->resources);
        self::assertTrue($result->value->truncated);
    }

    public function testReturnsEmptySuccessfulInventoryForUnmatchedFilter(): void
    {
        $result = (new ResourceInventoryQuery())->listInWorkspace($this->workspace(), 'page', 'missing');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceInventory::class, $result->value);
        self::assertSame([], $result->value->resources);
        self::assertSame(0, $result->value->total);
    }

    public function testRejectsInvalidFilters(): void
    {
        $query = new ResourceInventoryQuery();

        self::assertSame(SemanticStatus::InvalidInput, $query->listInWorkspace($this->workspace(), 'http')->status);
        self::assertSame(
            SemanticStatus::InvalidInput,
            $query->listInWorkspace($this->workspace(), prefix: '../user')->status,
        );
        self::assertSame(SemanticStatus::InvalidInput, $query->listInWorkspace($this->workspace(), limit: 0)->status);
        self::assertSame(SemanticStatus::InvalidInput, $query->listInWorkspace($this->workspace(), limit: 201)->status);
    }

    public function testDoesNotReadResourceSymlinkOutsideWorkspace(): void
    {
        $outside = $this->temporaryRoot . '/Outside.php';
        self::assertNotFalse(file_put_contents($outside, '<?php throw new RuntimeException();'));
        self::assertTrue(symlink($outside, $this->workspace . '/one/Resource/App/Escape.php'));

        $result = (new ResourceInventoryQuery())->listInWorkspace($this->workspace());

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceInventory::class, $result->value);
        self::assertSame(4, $result->value->total);
    }

    private function workspace(): WorkspaceContext
    {
        $result = WorkspaceContext::fromRoot($this->workspace);
        self::assertInstanceOf(WorkspaceContext::class, $result->value);

        return $result->value;
    }

    private function writeResource(string $relativePath): void
    {
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/' . $relativePath,
            '<?php class Resource extends \\BEAR\\Resource\\ResourceObject {}',
        ));
    }

    private function relative(string $path): string
    {
        $canonicalWorkspace = realpath($this->workspace);
        self::assertNotFalse($canonicalWorkspace);

        return substr($path, strlen($canonicalWorkspace) + 1);
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
