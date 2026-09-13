<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Sql;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Sql\SqlReferences;
use Suzumaze\BearPhpactor\Semantic\Sql\SqlReferencesQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

final class SqlReferencesQueryTest extends TestCase
{
    private string $temporaryRoot;
    private string $workspace;
    private string $outside;

    protected function setUp(): void
    {
        $temporaryDirectory = realpath(sys_get_temp_dir());
        self::assertNotFalse($temporaryDirectory);
        $this->temporaryRoot = $temporaryDirectory . '/bear-sql-references-' . bin2hex(random_bytes(8));
        $this->workspace = $this->temporaryRoot . '/workspace';
        $this->outside = $this->temporaryRoot . '/outside';
        self::assertTrue(mkdir($this->workspace . '/src/Query', 0777, true));
        self::assertTrue(mkdir($this->workspace . '/var/db/sql', 0777, true));
        self::assertTrue(mkdir($this->outside, 0777, true));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/composer.json',
            json_encode([
                'autoload' => [
                    'psr-4' => [
                        'Acme\\App\\' => 'src/',
                        'Outside\\' => $this->outside,
                    ],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/var/db/sql/point_distance.sql',
            'SELECT 1;',
        ));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/src/Query/Attribute.php',
            <<<'PHP'
<?php
use Ray\MediaQuery\Annotation\DbQuery as MediaQuery;
#[MediaQuery(id: 'point_distance', type: 'row')]
interface AttributeQuery {}
PHP,
        ));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/src/Query/Legacy.php',
            <<<'PHP'
<?php
/** @Query("point_distance") */
interface LegacyQuery {}
PHP,
        ));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/src/Query/Ignored.php',
            <<<'PHP'
<?php
use Ray\MediaQuery\Annotation\DbQuery;
#[\Foo\DbQuery('point_distance')]
#[DbQuery(type: 'point_distance')]
#[DbQuery(self::QUERY_ID)]
interface IgnoredQuery {}
// @Query("point_distance")
$value = '@Query("point_distance")';
PHP,
        ));
        self::assertNotFalse(file_put_contents(
            $this->outside . '/Outside.php',
            '<?php /** @Query("point_distance") */ interface OutsideQuery {}',
        ));
        self::assertTrue(symlink(
            $this->outside . '/Outside.php',
            $this->workspace . '/src/Query/Escape.php',
        ));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/src/Query/Large.php',
            '<?php /** @Query("point_distance") */' . str_repeat(' ', 1_048_576),
        ));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryRoot);
    }

    public function testFindsStaticReferencesToAnExistingSqlFileDeterministically(): void
    {
        $result = (new SqlReferencesQuery())->findInWorkspace(
            $this->context(),
            'point_distance',
            'src/Query/Attribute.php',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(SqlReferences::class, $result->value);
        self::assertSame(
            $this->workspace . '/var/db/sql/point_distance.sql',
            $result->value->sql->file,
        );
        self::assertSame(
            ['src/Query/Attribute.php', 'src/Query/Legacy.php'],
            array_map(fn ($reference): string => $this->relative($reference->file), $result->value->references),
        );
        self::assertSame(
            ['point_distance', 'point_distance'],
            array_column($result->value->references, 'queryId'),
        );

        foreach ($result->value->references as $reference) {
            $source = (string) file_get_contents($reference->file);
            self::assertSame(
                'point_distance',
                substr($source, $reference->contentStart, $reference->contentEnd - $reference->contentStart),
            );
        }
    }

    public function testDoesNotInventReferencesForAMissingSqlFile(): void
    {
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/src/Query/Missing.php',
            '<?php /** @Query("missing_query") */ interface MissingQuery {}',
        ));

        $result = (new SqlReferencesQuery())->findInWorkspace(
            $this->context(),
            'missing_query',
            'src/Query/Missing.php',
        );

        self::assertSame(SemanticStatus::NotFound, $result->status);
        self::assertNull($result->value);
    }

    public function testRejectsInvalidInputBeforeScanning(): void
    {
        $result = (new SqlReferencesQuery())->findInWorkspace(
            $this->context(),
            '../point_distance',
            'src/Query/Attribute.php',
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

    private function relative(string $path): string
    {
        return substr($path, strlen($this->workspace) + 1);
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
