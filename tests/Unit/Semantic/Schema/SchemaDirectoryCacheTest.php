<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Schema;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Suzumaze\BearPhpactor\Semantic\App\AppContextListQuery;
use Suzumaze\BearPhpactor\Semantic\App\ContextDiscoveryTrace;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\BearPackageComposition;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSourceIndex;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ModuleInterpreter;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaDirectoryQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

use function array_column;
use function bin2hex;
use function copy;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function realpath;
use function rmdir;
use function str_replace;
use function sys_get_temp_dir;
use function unlink;

/**
 * The Qiq and JSON Schema directories are kept across requests and reused only while what they
 * were read from is unchanged. Each test edits a copy of the QiqTemplates fixture, whose HTML
 * context binds `templates/`.
 */
final class SchemaDirectoryCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        SchemaDirectoryQuery::forgetSharedAnswers();
        $this->root = sys_get_temp_dir() . '/bear-schema-cache-' . bin2hex(random_bytes(6));
        $this->copy(dirname(__DIR__, 3) . '/Fixture/QiqTemplates', $this->root);
        $this->root = (string) realpath($this->root);
    }

    protected function tearDown(): void
    {
        SchemaDirectoryQuery::forgetSharedAnswers();
        $this->remove($this->root);
    }

    public function testAnEditedModuleIsReadAgain(): void
    {
        self::assertSame(['templates'], $this->qiqDirectories());

        mkdir($this->root . '/views');
        $this->replace('src/Module/HtmlModule.php', "'/templates'", "'/views'");

        self::assertSame(['views'], $this->qiqDirectories());
    }

    public function testANewEntryPointAndModuleAreFound(): void
    {
        self::assertSame('configured', $this->qiq()['status']);

        mkdir($this->root . '/bin');
        file_put_contents(
            $this->root . '/bin/prod.php',
            "<?php\n\\BEAR\\Package\\Injector::getInstance('Vendor\\\\App', 'prod-html-app');\n",
        );
        file_put_contents($this->root . '/src/Module/ProdModule.php', <<<'PHP'
            <?php

            namespace Vendor\App\Module;

            use BEAR\QiqModule\QiqModule;
            use Ray\Di\AbstractModule;

            class ProdModule extends AbstractModule
            {
                protected function configure(): void
                {
                    $this->install(new QiqModule(dirname(__DIR__, 2) . '/var/qiq/template'));
                }
            }
            PHP);

        // html-app and prod-html-app now bind different directories, so neither is the answer.
        self::assertSame(['templates', 'var/qiq/template'], $this->qiqDirectories());
    }

    public function testAChangedComposerJsonIsReadAgain(): void
    {
        self::assertSame('configured', $this->qiq()['status']);

        // QiqModule can no longer be found, so the HTML context cannot be composed.
        $this->replace('composer.json', '"framework/QiqModule/"', '"framework/Missing/"');

        $qiq = $this->qiq();
        self::assertSame('unsupported', $qiq['status']);
        self::assertArrayHasKey('contextDiscovery', $qiq);
    }

    public function testASavedResourceKeepsTheDiscoveredContexts(): void
    {
        $trace = $this->discover();

        $this->replace(
            'src/Resource/Page/Index.php',
            '{',
            "{\n    public function onPut(): static { return \$this; }\n",
        );
        file_put_contents(
            $this->root . '/src/Resource/Page/About.php',
            "<?php\n\nnamespace Vendor\\App\\Resource\\Page;\n",
        );

        self::assertTrue($this->stillAnswers($trace));
    }

    public function testAResourceThatNamesAContextIsDiscoveredAgain(): void
    {
        $trace = $this->discover();

        $this->replace(
            'src/Resource/Page/Index.php',
            '{',
            "{\n    public function boot(): void\n    {\n"
            . "        \\BEAR\\Package\\Injector::getInstance('Vendor\\\\App', 'cli-app');\n    }\n",
        );

        self::assertFalse($this->stillAnswers($trace));
    }

    public function testAFileThatCallsAForwarderIsDiscoveredAgain(): void
    {
        file_put_contents($this->root . '/src/Boot.php', <<<'PHP'
            <?php

            namespace Vendor\App;

            final class Boot
            {
                public static function run(string $context): void
                {
                    \BEAR\Package\Injector::getInstance('Vendor\\App', $context);
                }
            }
            PHP);
        $trace = $this->discover();

        $this->replace(
            'src/Resource/Page/Index.php',
            '{',
            "{\n    public function boot(): void { \\Vendor\\App\\Boot::run('cli-app'); }\n",
        );

        self::assertFalse($this->stillAnswers($trace));
    }

    public function testComposingTheDirectoriesReadsNoResourceFile(): void
    {
        $resource = $this->root . '/src/Resource/Page/Index.php';

        self::assertNotContains($resource, $this->composedInputs(true));
        // Control: a full composition lists the Resources, so the file is read.
        self::assertContains($resource, $this->composedInputs(false));
    }

    /** @return list<string> */
    private function composedInputs(bool $directoriesOnly): array
    {
        $classes = new ClassSourceIndex($this->root, $this->root);
        $interpreter = new ModuleInterpreter($classes, captureSchemaDirectories: $directoriesOnly);
        (new BearPackageComposition($classes, $interpreter))('Vendor\\App', 'html-app');

        return $classes->inputs()->filePaths();
    }

    private function stillAnswers(ContextDiscoveryTrace $trace): bool
    {
        return $trace->stillAnswers($this->workspace()->accessPolicy(), AppContextListQuery::MAX_FILES);
    }

    private function discover(): ContextDiscoveryTrace
    {
        $trace = new ContextDiscoveryTrace();
        (new AppContextListQuery())->listInWorkspace($this->workspace(), limit: 100, trace: $trace);
        self::assertTrue($trace->complete());

        return $trace;
    }

    /** @return array<string, mixed> */
    private function qiq(): array
    {
        return (new SchemaDirectoryQuery())->inspect($this->workspace(), null, 'qiq', null);
    }

    /** @return list<string|null> */
    private function qiqDirectories(): array
    {
        $directories = array_column($this->qiq()['candidates'], 'directory');
        sort($directories);

        return array_values(array_unique($directories));
    }

    private function workspace(): WorkspaceContext
    {
        $workspace = WorkspaceContext::fromRoot($this->root)->value;
        self::assertNotNull($workspace);

        return $workspace;
    }

    private function replace(string $file, string $search, string $replace): void
    {
        $path = $this->root . '/' . $file;
        $contents = (string) file_get_contents($path);
        self::assertStringContainsString($search, $contents);
        file_put_contents($path, preg_replace('/' . preg_quote($search, '/') . '/', $replace, $contents, 1));
    }

    private function copy(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            $target = $to . '/' . str_replace($from . '/', '', $item->getPathname());
            $item->isDir() ? mkdir($target) : copy($item->getPathname(), $target);
        }
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item instanceof SplFileInfo) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
