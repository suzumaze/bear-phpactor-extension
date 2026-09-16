<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Resource;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceAttributeIndex;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceAttributeIndexQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceInventoryIndex;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceInventoryQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

final class ResourceAttributeIndexQueryTest extends TestCase
{
    private string $temporaryRoot;
    private string $workspace;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/bear-resource-attributes-' . bin2hex(random_bytes(8));
        $this->workspace = $this->temporaryRoot . '/workspace';
        self::assertTrue(mkdir($this->workspace . '/src/Resource/App', 0777, true));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/composer.json',
            '{"autoload":{"psr-4":{"Acme\\\\App\\\\":"src/"}}}',
        ));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/src/Resource/App/Broken.php',
            '<?php namespace Acme\App\Resource\App; final class Broken extends \BEAR\Resource\ResourceObject {}',
        ));
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/src/Resource/App/Good.php',
            <<<'PHP'
<?php
namespace Acme\App\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;

#[Cacheable(expirySecond: 60)]
final class Good extends \BEAR\Resource\ResourceObject
{
    #[Embed(rel: 'user', src: 'app://self/user')]
    public function onGet(): void {}
}
PHP,
        ));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryRoot);
    }

    public function testKeepsSuccessfulAttributeFactsWhenAnotherResourceCannotBeParsed(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->workspace);
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $inventory = new ResourceInventoryQuery(new ResourceInventoryIndex(true));
        self::assertSame(SemanticStatus::Ok, $inventory->listInWorkspace($workspace->value, 'app')->status);
        self::assertNotFalse(file_put_contents(
            $this->workspace . '/src/Resource/App/Broken.php',
            '<?php echo "no class";',
        ));

        $result = (new ResourceAttributeIndexQuery($inventory))->listInWorkspace($workspace->value, 'app');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceAttributeIndex::class, $result->value);
        self::assertSame(2, $result->value->total);
        self::assertFalse($result->value->truncated);
        self::assertSame(
            ['app://self/broken', 'app://self/good'],
            array_map(static fn ($item): string => $item->resource->uri->uri(), $result->value->items),
        );
        self::assertSame(
            [SemanticStatus::ParseError, SemanticStatus::Ok],
            array_map(static fn ($item): SemanticStatus => $item->status, $result->value->items),
        );
        self::assertSame([], $result->value->items[0]->attributes);
        self::assertSame(
            ['Cacheable', 'Embed'],
            array_map(static fn ($attribute): string => $attribute->name, $result->value->items[1]->attributes),
        );
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
