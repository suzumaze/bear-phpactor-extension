<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Config;

use Suzumaze\BearPhpactor\Config\PhpactorConfigGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The .phpactor.json generator: environment extension list from a running
 * phpactor, own extension first, idempotent, other keys preserved.
 */
final class PhpactorConfigGeneratorTest extends TestCase
{
    private const EXTENSION_CLASS = 'Suzumaze\BearPhpactor\BearSundayExtension';

    private const FAKE_PHPACTOR = __DIR__ . '/../../Fixture/Config/fake-phpactor';

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/bear-phpactor-extension-test-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectRoot);
        putenv('FAKE_PHPACTOR_MODE');
    }

    public function testGeneratesConfigWithOwnExtensionFirst(): void
    {
        $result = $this->generator()->generate();

        self::assertTrue($result['created']);
        self::assertSame(3, $result['extension_count']);
        self::assertSame($this->projectRoot . '/.phpactor.json', $result['config_file']);

        $config = $this->readConfig();
        self::assertSame(
            [self::EXTENSION_CLASS, 'Phpactor\Extension\Core\CoreExtension', 'Phpactor\Extension\Debug\DebugExtension'],
            $config['container.extension_classes']
        );
    }

    public function testIsIdempotent(): void
    {
        $this->generator()->generate();
        $first = (string) file_get_contents($this->projectRoot . '/.phpactor.json');

        $result = $this->generator()->generate();

        self::assertFalse($result['created']);
        self::assertSame($first, (string) file_get_contents($this->projectRoot . '/.phpactor.json'));

        $config = $this->readConfig();
        self::assertCount(3, $config['container.extension_classes']);
        self::assertSame(self::EXTENSION_CLASS, $config['container.extension_classes'][0]);
    }

    public function testPreservesOtherKeys(): void
    {
        file_put_contents(
            $this->projectRoot . '/.phpactor.json',
            "{\n    \"language_server_phpstan.enabled\": false,\n    \"foo\": \"bar\"\n}\n"
        );

        $this->generator()->generate();

        $config = $this->readConfig();
        self::assertFalse($config['language_server_phpstan.enabled']);
        self::assertSame('bar', $config['foo']);
        self::assertSame(self::EXTENSION_CLASS, $config['container.extension_classes'][0]);
    }

    public function testThrowsWhenPhpactorFails(): void
    {
        putenv('FAKE_PHPACTOR_MODE=fail');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('phpactor config:dump failed');

        $this->generator()->generate();
    }

    public function testThrowsWhenPhpactorOutputIsNotJson(): void
    {
        putenv('FAKE_PHPACTOR_MODE=garbage');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not return valid JSON');

        $this->generator()->generate();
    }

    public function testThrowsWhenExtensionListIsEmpty(): void
    {
        putenv('FAKE_PHPACTOR_MODE=empty');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty extension list');

        $this->generator()->generate();
    }

    public function testThrowsWhenExistingConfigIsInvalidJson(): void
    {
        file_put_contents($this->projectRoot . '/.phpactor.json', "{ not json\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not valid JSON');

        $this->generator()->generate();
    }

    public function testThrowsWhenPhpactorBinaryDoesNotExist(): void
    {
        $generator = new PhpactorConfigGenerator(
            $this->projectRoot . '/no-such-phpactor',
            $this->projectRoot
        );

        // The failure path differs by PHP version and OS: proc_open may
        // refuse to start the missing binary ("Could not start phpactor") or
        // start it and observe exit code 127 ("phpactor config:dump failed").
        // Assert only the exception type. The message is not asserted:
        // the temp dir name itself contains "phpactor", so a message check
        // would pass even for an unrelated failure.
        $this->expectException(RuntimeException::class);

        $generator->generate();
    }

    private function generator(): PhpactorConfigGenerator
    {
        return new PhpactorConfigGenerator(
            (string) realpath(self::FAKE_PHPACTOR),
            $this->projectRoot
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readConfig(): array
    {
        $config = json_decode((string) file_get_contents($this->projectRoot . '/.phpactor.json'), true);
        self::assertIsArray($config);

        return $config;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
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
