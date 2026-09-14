<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Template;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Template\TemplateReferences;
use Suzumaze\BearPhpactor\Semantic\Template\TemplateReferencesQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;
use Suzumaze\BearPhpactor\Template\TemplateReference;

final class TemplateReferencesQueryTest extends TestCase
{
    public function testFindsTwigReferencesResolvingToTheSameFile(): void
    {
        $result = (new TemplateReferencesQuery())->findInWorkspace(
            $this->workspace(),
            TemplateReference::ENGINE_TWIG,
            'element/component/card.html.twig',
            'src/Resource/Page/TwigReferences.html.twig',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(TemplateReferences::class, $result->value);
        self::assertSame(
            self::fixtureDir() . '/var/templates/element/component/card.html.twig',
            $result->value->template->file,
        );
        self::assertSame([
            'src/Resource/Page/TwigReferences.html.twig',
            'src/Resource/Page/TwigReferencesSecond.html.twig',
        ], array_map(fn ($reference): string => $this->relative($reference->sourceFile), $result->value->references));
    }

    public function testFindsQiqReferencesButIgnoresOrdinaryPhpOutsideTemplateRoot(): void
    {
        $result = (new TemplateReferencesQuery())->findInWorkspace(
            $this->workspace(),
            TemplateReference::ENGINE_QIQ,
            'partial/card',
            'var/qiq/template/Page/QiqReferences.php',
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(TemplateReferences::class, $result->value);
        self::assertSame([
            'var/qiq/template/Page/QiqReferences.php',
            'var/qiq/template/Page/QiqReferences.php',
        ], array_map(fn ($reference): string => $this->relative($reference->sourceFile), $result->value->references));
    }

    public function testMissingAndInvalidTargetsReturnNoReferencePayload(): void
    {
        $query = new TemplateReferencesQuery();
        $missing = $query->findInWorkspace(
            $this->workspace(),
            TemplateReference::ENGINE_TWIG,
            'missing.html.twig',
            'src/Resource/Page/TwigReferences.html.twig',
        );
        $invalid = $query->findInWorkspace(
            $this->workspace(),
            TemplateReference::ENGINE_TWIG,
            '../../escape.html.twig',
            'src/Resource/Page/TwigReferences.html.twig',
        );

        self::assertSame(SemanticStatus::NotFound, $missing->status);
        self::assertNull($missing->value);
        self::assertSame(SemanticStatus::InvalidInput, $invalid->status);
        self::assertNull($invalid->value);
    }

    private function workspace(): WorkspaceContext
    {
        $workspace = WorkspaceContext::fromRoot(self::fixtureDir());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        return $workspace->value;
    }

    private function relative(string $path): string
    {
        return substr($path, strlen(self::fixtureDir()) + 1);
    }

    private static function fixtureDir(): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/Template');
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
