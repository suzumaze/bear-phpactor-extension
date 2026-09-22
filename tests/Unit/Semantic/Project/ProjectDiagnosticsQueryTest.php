<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Project;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectDiagnostics;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectDiagnosticsQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceInventoryIndex;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceInventoryQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

final class ProjectDiagnosticsQueryTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/bear-project-diagnostics-' . bin2hex(random_bytes(8));
        foreach (
            [
            '/src/Resource/App',
            '/src/Resource/Page',
            '/var/db/sql',
            '/var/json_schema',
            '/var/json_validate',
            '/var/qiq/template/App',
            ] as $directory
        ) {
            self::assertTrue(mkdir($this->workspace . $directory, 0777, true));
        }
        $this->write('/composer.json', '{"autoload":{"psr-4":{"Acme\\\\App\\\\":"src/"}}}');
        $this->write('/src/Client.php', <<<'PHP'
<?php
namespace Acme\App;
use Ray\MediaQuery\Annotation\DbQuery;
#[DbQuery('missing_query')]
final class Client { public string $uri = 'app://self/missing'; }
PHP);
        $this->write('/src/Resource/App/Broken.php', <<<'PHP'
<?php
namespace Acme\App\Resource\App;
final class Broken extends \BEAR\Resource\ResourceObject {}
PHP);
        $this->write('/src/Resource/App/Article.php', <<<'PHP'
<?php
namespace Acme\App\Resource\App;
use BEAR\ApiDoc\Annotation\Alps;
use BEAR\Resource\Annotation\JsonSchema;
use BEAR\Resource\Annotation\Link;
#[Alps('missingDescriptor')]
final class Article extends \BEAR\Resource\ResourceObject
{
    #[JsonSchema('broken.json')]
    #[Link(rel: 'user', href: 'app://self/user', method: 'post')]
    public function onGet(): void {}
}
PHP);
        $this->write('/src/Resource/App/User.php', <<<'PHP'
<?php
namespace Acme\App\Resource\App;
final class User extends \BEAR\Resource\ResourceObject
{
    public function onGet(): void {}
}
PHP);
        $this->write('/src/Resource/App/Compare.php', <<<'PHP'
<?php
namespace Acme\App\Resource\App;
use BEAR\Resource\Annotation\JsonSchema;
final class Compare extends \BEAR\Resource\ResourceObject
{
    #[JsonSchema(params: 'compare.json')]
    public function onPost(string $id): void {}
}
PHP);
        $this->write('/src/Resource/App/Linky.php', <<<'PHP'
<?php
namespace Acme\App\Resource\App;
use BEAR\Resource\Annotation\Link;
final class Linky extends \BEAR\Resource\ResourceObject
{
    #[Link(rel: 'ghost', href: 'app://self/ghost')]
    public function onGet(): void {}
}
PHP);
        $this->write('/aura.route.php', "<?php \$map->route('/missing-page', '/missing');");
        $this->write('/var/json_schema/broken.json', '{broken');
        $this->write('/var/json_validate/compare.json', '{"type":"object","properties":{"name":{"type":"string"}}}');
        $this->write('/apidoc.xml', '<apidoc><alps>profile.json</alps></apidoc>');
        $this->write('/profile.json', '{"alps":{"descriptor":[]}}');
        $this->write('/var/qiq/template/App/View.php', "<?php \$this->render('missing-template');");
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testReportsIndependentSavedSourceProblemsWithoutFailingTheOuterQuery(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->workspace);
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $inventory = new ResourceInventoryQuery(new ResourceInventoryIndex(true));
        self::assertSame(SemanticStatus::Ok, $inventory->listInWorkspace($workspace->value)->status);
        $this->write('/src/Resource/App/Broken.php', '<?php echo "no resource class";');

        $result = (new ProjectDiagnosticsQuery(inventoryQuery: $inventory))
            ->diagnoseInWorkspace($workspace->value, limit: 100);

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ProjectDiagnostics::class, $result->value);
        self::assertFalse($result->value->truncated);
        self::assertSame($result->value->total, count($result->value->items));
        self::assertGreaterThanOrEqual(5, $result->value->scannedResources);
        self::assertFalse($result->value->resourceScanTruncated);
        self::assertSame([], $result->value->skippedChecks);
        self::assertGreaterThanOrEqual(6, $result->value->scannedFiles);

        $codes = array_column($result->value->items, 'code');
        foreach (
            [
            'alps_descriptor_not_found',
            'contract_name_mismatch',
            'relation_method_not_found',
            'resource_facts_malformed',
            'resource_reference_not_found',
            'route_resource_not_found',
            'schema_reference_malformed',
            'sql_reference_not_found',
            'template_reference_not_found',
            ] as $code
        ) {
            self::assertContains($code, $codes);
        }
        $ghostItems = array_values(array_filter(
            $result->value->items,
            static fn ($item): bool => $item->subject === 'app://self/ghost',
        ));
        self::assertCount(1, $ghostItems);
        self::assertSame('relation_target_not_found', $ghostItems[0]->code);
        $contractItems = array_values(array_filter(
            $result->value->items,
            static fn ($item): bool => $item->code === 'contract_name_mismatch',
        ));
        self::assertNotEmpty($contractItems);
        self::assertSame(SemanticStatus::Ok, $contractItems[0]->status);
        self::assertStringNotContainsString(
            $this->workspace,
            json_encode($result->value, JSON_THROW_ON_ERROR),
        );
    }

    public function testPaginatesItemsButKeepsTheCompleteCount(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->workspace);
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $query = new ProjectDiagnosticsQuery();
        $first = $query->diagnoseInWorkspace($workspace->value, limit: 1);
        $second = $query->diagnoseInWorkspace($workspace->value, limit: 1, offset: 1);

        self::assertSame(SemanticStatus::Ok, $first->status);
        self::assertInstanceOf(ProjectDiagnostics::class, $first->value);
        self::assertInstanceOf(ProjectDiagnostics::class, $second->value);
        self::assertCount(1, $first->value->items);
        self::assertCount(1, $second->value->items);
        self::assertNotSame($first->value->items[0], $second->value->items[0]);
        self::assertGreaterThan(1, $first->value->total);
        self::assertSame(0, $first->value->offset);
        self::assertSame(1, $second->value->offset);
        self::assertTrue($first->value->truncated);
    }

    public function testRejectsAnUnboundedLimit(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->workspace);
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = (new ProjectDiagnosticsQuery())->diagnoseInWorkspace(
            $workspace->value,
            limit: ProjectDiagnosticsQuery::MAX_LIMIT + 1,
        );

        self::assertSame(SemanticStatus::InvalidInput, $result->status);

        self::assertSame(
            SemanticStatus::InvalidInput,
            (new ProjectDiagnosticsQuery())->diagnoseInWorkspace($workspace->value, offset: -1)->status,
        );
    }

    public function testDoesNotReportSqlReferencesWhenTheKnownConventionRootIsAbsent(): void
    {
        self::assertTrue(rmdir($this->workspace . '/var/db/sql'));
        $workspace = WorkspaceContext::fromRoot($this->workspace);
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = (new ProjectDiagnosticsQuery())->diagnoseInWorkspace($workspace->value);

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ProjectDiagnostics::class, $result->value);
        self::assertNotContains('sql_reference_not_found', array_column($result->value->items, 'code'));
        self::assertSame(['sql_references'], $result->value->skippedChecks);
    }

    public function testScansTheCompleteResourceInventoryBeyondThePublicPageLimit(): void
    {
        for ($index = 0; $index <= ResourceInventoryQuery::MAX_LIMIT; ++$index) {
            $class = 'Bulk' . $index;
            $this->write(
                '/src/Resource/App/' . $class . '.php',
                sprintf(
                    '<?php namespace Acme\\App\\Resource\\App; '
                        . 'final class %s extends \\BEAR\\Resource\\ResourceObject {}',
                    $class,
                ),
            );
        }
        $workspace = WorkspaceContext::fromRoot($this->workspace);
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = (new ProjectDiagnosticsQuery())->diagnoseInWorkspace($workspace->value);

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ProjectDiagnostics::class, $result->value);
        self::assertGreaterThan(ResourceInventoryQuery::MAX_LIMIT, $result->value->scannedResources);
        self::assertFalse($result->value->resourceScanTruncated);
    }

    public function testPreservesAmbiguousAndInvalidInputReferenceStatuses(): void
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/Resource');
        self::assertNotFalse($fixture);
        $workspace = WorkspaceContext::fromRoot($fixture);
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = (new ProjectDiagnosticsQuery())->diagnoseInWorkspace($workspace->value);

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ProjectDiagnostics::class, $result->value);
        $bySubject = [];
        foreach ($result->value->items as $item) {
            $bySubject[$item->subject] = $item->status;
        }
        self::assertSame(SemanticStatus::Ambiguous, $bySubject['page://self/x'] ?? null);
        self::assertSame(SemanticStatus::InvalidInput, $bySubject['app://self/../../Client'] ?? null);
    }

    private function write(string $path, string $contents): void
    {
        self::assertNotFalse(file_put_contents($this->workspace . $path, $contents));
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
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
        }
        rmdir($path);
    }
}
