<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Template;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;
use Suzumaze\BearPhpactor\Semantic\Template\TemplateReferencesQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

use function Amp\Promise\wait;
use function array_column;
use function array_map;
use function dirname;

/**
 * Qiq templates are found in the directory the DI modules give BEAR.QiqModule, not only in
 * `var/qiq/template`. Both fixtures keep a decoy template at the convention path.
 */
final class QiqTemplateRootTest extends TestCase
{
    public function testTheDirectoryGivenToQiqModuleIsSearched(): void
    {
        $handler = $this->handler('QiqTemplates');

        $forResource = wait($handler->resolveResourceTemplate('page://self/index', 'qiq'));
        self::assertSame('ok', $forResource['status']);
        self::assertSame('templates/Page/Index.php', $forResource['data']['path']);

        $byName = wait($handler->resolveTemplate('qiq', 'layout/base'));
        self::assertSame('ok', $byName['status']);
        self::assertSame('templates/layout/base.php', $byName['data']['path']);

        $relative = wait($handler->resolveTemplate('qiq', '../layout/base', 'templates/Page/Index.php'));
        self::assertSame('templates/layout/base.php', $relative['data']['path']);

        $description = wait($handler->describeResource('page://self/index'));
        self::assertSame(
            ['templates/Page/Index.php'],
            array_column($description['data']['templates'], 'path'),
        );
        self::assertArrayNotHasKey('templatesUnresolved', $description['data']);

        $workspace = WorkspaceContext::fromRoot(dirname(__DIR__, 3) . '/Fixture/QiqTemplates')->value;
        self::assertNotNull($workspace);
        $references = (new TemplateReferencesQuery())->findInWorkspace($workspace, 'qiq', 'layout/base');
        self::assertSame(
            ['templates/Page/Index.php'],
            array_map(
                static fn ($reference): string => $workspace->accessPolicy()
                    ->inspectExisting($reference->sourceFile)->value->relative ?? '',
                $references->value->references ?? [],
            ),
        );
    }

    public function testADirectoryKnownOnlyAtRuntimeIsUnsupportedNotTheConvention(): void
    {
        $handler = $this->handler('QiqTemplatesDynamic');

        self::assertSame('unsupported', wait($handler->resolveResourceTemplate('page://self/index', 'qiq'))['status']);
        self::assertSame('unsupported', wait($handler->resolveTemplate('qiq', 'layout/base'))['status']);
        $description = wait($handler->describeResource('page://self/index'));
        self::assertSame('ok', $description['status']);
        self::assertSame([], $description['data']['templates']);
        self::assertSame(['qiq'], $description['data']['templatesUnresolved']);
    }

    private function handler(string $fixture): SemanticQueryHandler
    {
        return new SemanticQueryHandler(dirname(__DIR__, 3) . '/Fixture/' . $fixture);
    }
}
