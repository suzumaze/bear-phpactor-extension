<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Workspace;

use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceAccessPolicy;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspacePath;
use PHPUnit\Framework\TestCase;

final class WorkspaceAccessPolicyTest extends TestCase
{
    private string $temporaryRoot;
    private string $workspace;
    private string $outside;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/bear-semantic-' . bin2hex(random_bytes(8));
        $this->workspace = $this->temporaryRoot . '/workspace';
        $this->outside = $this->temporaryRoot . '/outside';
        self::assertTrue(mkdir($this->workspace, 0777, true));
        self::assertTrue(mkdir($this->outside, 0777, true));
        self::assertNotFalse(file_put_contents($this->workspace . '/schema..v2.json', '{}'));
        self::assertNotFalse(file_put_contents($this->workspace . '/inside.json', '{}'));
        self::assertNotFalse(file_put_contents($this->outside . '/secret.json', '{}'));
        self::assertTrue(symlink($this->outside . '/secret.json', $this->workspace . '/escape.json'));
        self::assertTrue(symlink($this->workspace . '/inside.json', $this->workspace . '/inside-link.json'));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryRoot);
    }

    public function testCreatesPolicyOnlyForExistingAbsoluteDirectory(): void
    {
        $valid = WorkspaceAccessPolicy::fromRoot($this->workspace);
        $relative = WorkspaceAccessPolicy::fromRoot('workspace');
        $missing = WorkspaceAccessPolicy::fromRoot($this->temporaryRoot . '/missing');

        self::assertSame(SemanticStatus::Ok, $valid->status);
        self::assertInstanceOf(WorkspaceAccessPolicy::class, $valid->value);
        self::assertSame(SemanticStatus::InvalidInput, $relative->status);
        self::assertSame(SemanticStatus::NotFound, $missing->status);
    }

    public function testAcceptsValidNameContainingTwoDots(): void
    {
        $policy = $this->policy();

        $result = $policy->resolveExisting('schema..v2.json');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(WorkspacePath::class, $result->value);
        self::assertSame('schema..v2.json', $result->value->relative);
    }

    public function testRejectsUnsafeOrMissingCallerPathsWithoutThrowing(): void
    {
        $policy = $this->policy();

        self::assertSame(SemanticStatus::InvalidInput, $policy->resolveExisting('../outside/secret.json')->status);
        self::assertSame(SemanticStatus::InvalidInput, $policy->resolveExisting('/etc/passwd')->status);
        self::assertSame(SemanticStatus::InvalidInput, $policy->resolveExisting('dir\\file.php')->status);
        self::assertSame(SemanticStatus::InvalidInput, $policy->resolveExisting("bad\0name")->status);
        self::assertSame(SemanticStatus::NotFound, $policy->resolveExisting('missing.php')->status);
    }

    public function testRejectsSymlinkEscapeButAllowsCanonicalTargetInsideWorkspace(): void
    {
        $policy = $this->policy();

        $escape = $policy->resolveExisting('escape.json');
        $inside = $policy->resolveExisting('inside-link.json');

        self::assertSame(SemanticStatus::OutsideWorkspace, $escape->status);
        self::assertNull($escape->value);
        self::assertFalse($policy->containsExisting($this->outside . '/secret.json'));
        self::assertSame(SemanticStatus::Ok, $inside->status);
        self::assertInstanceOf(WorkspacePath::class, $inside->value);
        self::assertSame('inside.json', $inside->value->relative);
    }

    private function policy(): WorkspaceAccessPolicy
    {
        $result = WorkspaceAccessPolicy::fromRoot($this->workspace);
        self::assertInstanceOf(WorkspaceAccessPolicy::class, $result->value);

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
