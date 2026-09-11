<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Template;

use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Template\TemplateQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use PHPUnit\Framework\TestCase;

final class TemplateQueryWorkspaceTest extends TestCase
{
    private string $temporaryRoot;
    private string $workspace;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/bear-template-query-' . bin2hex(random_bytes(8));
        $this->workspace = $this->temporaryRoot . '/workspace';
        $outside = $this->temporaryRoot . '/outside';
        self::assertTrue(mkdir($this->workspace . '/src', 0777, true));
        self::assertTrue(mkdir($this->workspace . '/var/templates', 0777, true));
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
        self::assertNotFalse(file_put_contents($outside . '/escape.html.twig', 'outside'));
        self::assertTrue(symlink(
            $outside . '/escape.html.twig',
            $this->workspace . '/var/templates/escape.html.twig',
        ));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryRoot);
    }

    public function testRejectsTemplateWhoseSymlinkTargetEscapesWorkspace(): void
    {
        $result = (new TemplateQuery())->resolveInWorkspace($this->context(), 'twig', 'escape.html.twig');

        self::assertSame(SemanticStatus::OutsideWorkspace, $result->status);
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
