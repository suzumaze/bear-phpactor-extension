<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Config;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Generates or updates the `.phpactor.json` of a BEAR.Sunday project.
 *
 * phpactor's `container.extension_classes` parameter *replaces* the built-in
 * defaults instead of appending to them, so a project that wants this
 * extension must enumerate every built-in extension class plus this package's
 * own. The built-in list is a literal array inside `Phpactor::boot()` with no
 * public API, so the only reliable way to obtain it is to ask a running
 * phpactor. This class runs `phpactor config:dump --config-only` in a clean
 * temporary directory (so no project config can shadow the defaults), takes
 * the resolved `container.extension_classes` from the output, and writes it
 * to the project's `.phpactor.json` with this package's extension first.
 *
 * Re-running is safe: the extension is de-duplicated and always placed at the
 * front, and every other key of an existing `.phpactor.json` is preserved.
 * Re-running after a phpactor upgrade picks up newly added built-in
 * extensions.
 */
final class PhpactorConfigGenerator
{
    private const EXTENSION_CLASS = 'Suzumaze\BearPhpactor\BearSundayExtension';

    private const CONFIG_FILE = '.phpactor.json';

    private const EXTENSION_CLASSES_KEY = 'container.extension_classes';

    public function __construct(
        private string $phpactorBin,
        private string $projectRoot,
    ) {
    }

    /**
     * @return array{config_file: string, extension_count: int, created: bool}
     */
    public function generate(): array
    {
        $extensionClasses = $this->withSelfFirst($this->environmentExtensionClasses());

        $configFile = $this->projectRoot . '/' . self::CONFIG_FILE;
        $created = !is_file($configFile);
        $config = $this->readConfig($configFile);
        $config[self::EXTENSION_CLASSES_KEY] = $extensionClasses;
        $this->writeConfig($configFile, $config);

        return [
            'config_file' => $configFile,
            'extension_count' => count($extensionClasses),
            'created' => $created,
        ];
    }

    /**
     * The environment's built-in extension list, obtained from a running
     * phpactor in a clean directory.
     *
     * @return list<string>
     */
    private function environmentExtensionClasses(): array
    {
        $tempDir = $this->createTempDir();
        try {
            [$exitCode, $stdout, $stderr] = $this->runPhpactor([
                '--working-dir=' . $tempDir,
                'config:dump',
                '--config-only',
            ]);
            if ($exitCode !== 0) {
                throw new RuntimeException(sprintf(
                    'phpactor config:dump failed (exit %d): %s',
                    $exitCode,
                    trim($stderr)
                ));
            }

            $config = json_decode($stdout, true);
            if (!is_array($config)) {
                throw new RuntimeException('phpactor config:dump did not return valid JSON');
            }

            $classes = $config[self::EXTENSION_CLASSES_KEY] ?? null;
            if (!is_array($classes)) {
                throw new RuntimeException(sprintf(
                    'phpactor config:dump output has no "%s" parameter',
                    self::EXTENSION_CLASSES_KEY
                ));
            }

            $classes = array_values(array_filter(
                $classes,
                static fn (mixed $class): bool => is_string($class)
            ));
            if ($classes === []) {
                throw new RuntimeException('phpactor config:dump returned an empty extension list');
            }

            return $classes;
        } finally {
            $this->removeDir($tempDir);
        }
    }

    /**
     * @param list<string> $extensionClasses
     * @return list<string>
     */
    private function withSelfFirst(array $extensionClasses): array
    {
        $withoutSelf = array_values(array_filter(
            $extensionClasses,
            static fn (string $class): bool => $class !== self::EXTENSION_CLASS
        ));

        return [self::EXTENSION_CLASS, ...$withoutSelf];
    }

    /**
     * @return array<string, mixed>
     */
    private function readConfig(string $configFile): array
    {
        if (!is_file($configFile)) {
            return [];
        }

        $config = json_decode((string) file_get_contents($configFile), true);
        if (!is_array($config)) {
            throw new RuntimeException(sprintf('Existing %s is not valid JSON', $configFile));
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeConfig(string $configFile, array $config): void
    {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException(sprintf('Could not encode config as JSON: %s', json_last_error_msg()));
        }

        if (file_put_contents($configFile, $json . "\n") === false) {
            throw new RuntimeException(sprintf('Could not write %s', $configFile));
        }
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runPhpactor(array $args): array
    {
        $process = @proc_open(
            array_merge([$this->phpactorBin], $args),
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException(sprintf('Could not start phpactor: %s', $this->phpactorBin));
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, $stdout, $stderr];
    }

    private function createTempDir(): string
    {
        $tempDir = sys_get_temp_dir() . '/bear-phpactor-extension-' . bin2hex(random_bytes(6));
        if (!mkdir($tempDir, 0700) && !is_dir($tempDir)) {
            throw new RuntimeException(sprintf('Could not create temporary directory %s', $tempDir));
        }

        return $tempDir;
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
