<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Config;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class BearPhpactorInitTest extends TestCase
{
    private string $installRoot;

    private string $projectRoot;

    private string $packageBin;

    protected function setUp(): void
    {
        $this->installRoot = sys_get_temp_dir() . '/bear-phpactor-bin-test-' . bin2hex(random_bytes(6));
        $this->projectRoot = $this->installRoot . '/project';
        $this->packageBin = $this->installRoot
            . '/vendor/suzumaze/bear-phpactor-extension/bin/bear-phpactor-init';

        mkdir(dirname($this->packageBin), 0700, true);
        mkdir($this->installRoot . '/vendor/bin', 0700, true);
        mkdir($this->projectRoot, 0700, true);
        copy(dirname(__DIR__, 3) . '/bin/bear-phpactor-init', $this->packageBin);
        file_put_contents(
            $this->installRoot . '/vendor/autoload.php',
            sprintf("<?php\n\nrequire %s;\n", var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true))
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->installRoot);
    }

    public function testRunsThroughComposerBinProxy(): void
    {
        $proxy = $this->installRoot . '/vendor/bin/bear-phpactor-init';
        file_put_contents($proxy, <<<'PHP'
<?php

$GLOBALS['_composer_autoload_path'] = __DIR__ . '/../autoload.php';

return include __DIR__ . '/../suzumaze/bear-phpactor-extension/bin/bear-phpactor-init';
PHP);

        [$exitCode, $stdout, $stderr] = $this->runScript($proxy);

        self::assertSame(0, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertStringContainsString('Wrote 3 extension classes', $stdout);
        self::assertFileExists($this->projectRoot . '/.phpactor.json');
    }

    public function testRunsInstalledPackageBinaryDirectly(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runScript($this->packageBin);

        self::assertSame(0, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertStringContainsString('Wrote 3 extension classes', $stdout);
        self::assertFileExists($this->projectRoot . '/.phpactor.json');
    }

    /** @return array{int, string, string} */
    private function runScript(string $script): array
    {
        $process = proc_open(
            [PHP_BINARY, $script],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->projectRoot,
            [
                'PHPACTOR_BIN' => (string) realpath(__DIR__ . '/../../Fixture/Config/fake-phpactor'),
                'TMPDIR' => $this->installRoot,
            ]
        );
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
                continue;
            }
            unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
