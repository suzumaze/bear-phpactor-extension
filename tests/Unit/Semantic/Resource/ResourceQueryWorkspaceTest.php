<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Resource;

use Suzumaze\BearPhpactor\Semantic\Resource\ResourceQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceResolution;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Resource\Model\Project;
use PHPUnit\Framework\TestCase;

final class ResourceQueryWorkspaceTest extends TestCase
{
    private string $temporaryRoot;
    private string $workspace;
    private string $outside;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/bear-resource-query-' . bin2hex(random_bytes(8));
        $this->workspace = $this->temporaryRoot . '/workspace';
        $this->outside = $this->temporaryRoot . '/outside';
        self::assertTrue(mkdir($this->workspace . '/src/Resource/App', 0777, true));
        self::assertTrue(mkdir($this->outside, 0777, true));

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
        self::assertNotFalse(file_put_contents($this->workspace . '/src/Resource/App/User.php', '<?php'));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/src/AppModule.php',
            <<<'PHP'
<?php
use BEAR\Package\Module\Import\ImportApp;
new ImportApp('tags', 'Acme\Tags', 'app');
PHP,
        ));
        self::assertNotFalse(file_put_contents($this->outside . '/Escape.php', '<?php'));
        self::assertTrue(symlink(
            $this->outside . '/Escape.php',
            $this->workspace . '/src/Resource/App/Escape.php',
        ));
        self::assertTrue(mkdir($this->outside . '/tags-core/src/Resource/App/Api', 0777, true));
        self::assertNotFalse(file_put_contents(
            $this->outside . '/tags-core/src/Resource/App/Api/Search.php',
            '<?php namespace Acme\Tags\Resource\App\Api; final class Search {}',
        ));
        self::assertTrue(mkdir($this->workspace . '/vendor/acme', 0777, true));
        self::assertTrue(mkdir($this->workspace . '/vendor/composer', 0777, true));
        self::assertTrue(symlink(
            $this->outside . '/tags-core',
            $this->workspace . '/vendor/acme/tags-core',
        ));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/vendor/composer/installed.json',
            <<<'JSON'
{
    "packages": [{
        "name": "acme/tags-core",
        "install-path": "../acme/tags-core",
        "autoload": {"psr-4": {"Acme\\Tags\\": "src/"}}
    }]
}
JSON,
        ));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryRoot);
    }

    public function testResolvesResourceInsideExplicitWorkspace(): void
    {
        $result = (new ResourceQuery())->resolveInWorkspace(
            $this->context(),
            'app://self/user',
            'src/Client.php',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceResolution::class, $result->value);
        self::assertSame(realpath($this->workspace . '/src/Resource/App/User.php'), $result->value->file);
    }

    public function testRejectsResourceWhoseSymlinkTargetEscapesWorkspace(): void
    {
        $result = (new ResourceQuery())->resolveInWorkspace(
            $this->context(),
            'app://self/escape',
            'src/Client.php',
        );

        self::assertSame(SemanticStatus::OutsideWorkspace, $result->status);
        self::assertNull($result->value);
        self::assertSame([], $result->candidates);
    }

    public function testCoreResolutionAlsoRejectsSymlinkOutsideProject(): void
    {
        $project = Project::fromRoot($this->workspace);
        self::assertInstanceOf(Project::class, $project);

        $result = (new ResourceQuery())->resolveString($project, 'app://self/escape');

        self::assertSame(SemanticStatus::OutsideWorkspace, $result->status);
        self::assertNull($result->value);
    }

    public function testCoreResolutionTrustsComposerInstalledImportAppSymlink(): void
    {
        $project = Project::fromRoot($this->workspace);
        self::assertInstanceOf(Project::class, $project);

        $result = (new ResourceQuery())->resolveString($project, 'app://tags/api/search');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceResolution::class, $result->value);
        self::assertSame(
            realpath($this->outside . '/tags-core/src/Resource/App/Api/Search.php'),
            realpath($result->value->file),
        );
    }

    public function testWorkspaceResolutionStillRejectsExternalComposerInstalledImportApp(): void
    {
        $result = (new ResourceQuery())->resolveInWorkspace(
            $this->context(),
            'app://tags/api/search',
            'src/Client.php',
        );

        self::assertSame(SemanticStatus::OutsideWorkspace, $result->status);
        self::assertNull($result->value);
    }

    public function testRejectsInvalidContextBeforeResolvingResource(): void
    {
        $result = (new ResourceQuery())->resolveInWorkspace(
            $this->context(),
            'app://self/user',
            '../outside/Escape.php',
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
