<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Alps;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsDescriptorResolution;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use PHPUnit\Framework\TestCase;

final class AlpsQueryFailureTest extends TestCase
{
    private string $temporaryRoot;
    private string $workspace;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/bear-alps-query-' . bin2hex(random_bytes(8));
        $this->workspace = $this->temporaryRoot . '/workspace';
        self::assertTrue(mkdir($this->workspace . '/src', 0777, true));
        self::assertTrue(mkdir($this->workspace . '/var/alps', 0777, true));
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
        $this->writeApidoc();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryRoot);
    }

    public function testReportsMalformedXmlAndJson(): void
    {
        self::assertNotFalse(file_put_contents($this->workspace . '/apidoc.xml', '<apidoc><alps>'));
        self::assertSame(SemanticStatus::ParseError, $this->resolve('item')->status);

        $this->writeApidoc();
        self::assertNotFalse(file_put_contents($this->workspace . '/var/alps/profile.json', '{'));
        self::assertSame(SemanticStatus::ParseError, $this->resolve('item')->status);
    }

    public function testReportsDuplicateDescriptorIdAsDeterministicAmbiguity(): void
    {
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/var/alps/profile.json',
            '{"alps":{"descriptor":[{"id":"item"},{"id":"item"}]}}',
        ));

        $result = $this->resolve('item');

        self::assertSame(SemanticStatus::Ambiguous, $result->status);
        self::assertSame(
            [29, 43],
            array_map(
                static fn (AlpsDescriptorResolution $candidate): int => $candidate->offset,
                $result->candidates,
            ),
        );
    }

    public function testRejectsProfileSymlinkOutsideProject(): void
    {
        $outside = $this->temporaryRoot . '/outside.json';
        self::assertNotFalse(file_put_contents($outside, '{"alps":{"descriptor":[{"id":"item"}]}}'));
        self::assertTrue(symlink($outside, $this->workspace . '/var/alps/profile.json'));

        self::assertSame(SemanticStatus::OutsideWorkspace, $this->resolve('item')->status);
    }

    private function resolve(string $descriptorId): SemanticResult
    {
        $project = Project::fromRoot($this->workspace);
        self::assertNotNull($project);

        return (new AlpsQuery())->resolve($project, $descriptorId);
    }

    private function writeApidoc(): void
    {
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/apidoc.xml',
            '<apidoc><alps>var/alps/profile.json</alps></apidoc>',
        ));
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
