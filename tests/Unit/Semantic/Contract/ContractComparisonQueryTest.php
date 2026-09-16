<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Contract;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Contract\ContractComparison;
use Suzumaze\BearPhpactor\Semantic\Contract\ContractComparisonQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaQuery;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

final class ContractComparisonQueryTest extends TestCase
{
    public function testComparesRequestParametersSchemaAndAlpsByExactNamePresence(): void
    {
        $result = (new ContractComparisonQuery())->compareInWorkspace(
            $this->workspace(),
            'app://self/user',
            'onPost',
            SchemaQuery::KIND_REQUEST,
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ContractComparison::class, $result->value);
        self::assertSame(
            [
                ['resource', 'ok', 'onPost:parameters', ['id', 'name']],
                ['schema', 'ok', 'request:user-params.json', ['200', 'email', 'id', 'name']],
                ['alps', 'ok', 'createUser', ['email', 'id']],
            ],
            array_map(
                static fn ($surface): array => [
                    $surface->source,
                    $surface->status->value,
                    $surface->subject,
                    $surface->names,
                ],
                $result->value->surfaces,
            ),
        );
        self::assertNotNull($result->value->comparison);
        self::assertSame(['resource', 'schema', 'alps'], $result->value->comparison->compared);
        self::assertSame(['id'], $result->value->comparison->common);
        self::assertSame([], $result->value->comparison->onlyInResource);
        self::assertSame(['200'], $result->value->comparison->onlyInSchema);
        self::assertSame([], $result->value->comparison->onlyInAlps);
        self::assertSame(
            [
                ['200', ['schema']],
                ['email', ['schema', 'alps']],
                ['id', ['resource', 'schema', 'alps']],
                ['name', ['resource', 'schema']],
            ],
            array_map(
                static fn ($presence): array => [$presence->name, $presence->sources],
                $result->value->comparison->presence,
            ),
        );
        self::assertSame(
            ['src/Resource/App/User.php', 'var/alps/profile.json', 'var/json_validate/user-params.json'],
            array_values(array_filter(array_map(
                static fn ($evidence): ?string => $evidence->path,
                $result->provenance,
            ))),
        );
    }

    public function testComparesResponseSchemaWithAlpsRepresentationWithoutGuessingBodyShape(): void
    {
        $result = (new ContractComparisonQuery())->compareInWorkspace(
            $this->workspace(),
            'app://self/user',
            'onGet',
            SchemaQuery::KIND_RESPONSE,
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ContractComparison::class, $result->value);
        self::assertSame(SemanticStatus::Unsupported, $result->value->surfaces[0]->status);
        self::assertSame(['display_name', 'id', 'name'], $result->value->surfaces[1]->names);
        self::assertSame('User', $result->value->surfaces[2]->subject);
        self::assertSame(['id', 'name'], $result->value->surfaces[2]->names);
        self::assertNotNull($result->value->comparison);
        self::assertSame(['schema', 'alps'], $result->value->comparison->compared);
        self::assertSame(['id', 'name'], $result->value->comparison->common);
        self::assertSame(['display_name'], $result->value->comparison->onlyInSchema);
    }

    public function testReturnsSurfaceStatusesAndNoComparisonWhenOnlyOneSurfaceIsAvailable(): void
    {
        $result = (new ContractComparisonQuery())->compareInWorkspace(
            $this->workspace(),
            'app://self/user',
            'onMissing',
            SchemaQuery::KIND_REQUEST,
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ContractComparison::class, $result->value);
        self::assertSame(
            [SemanticStatus::NotFound, SemanticStatus::Unsupported, SemanticStatus::NotFound],
            array_map(static fn ($surface): SemanticStatus => $surface->status, $result->value->surfaces),
        );
        self::assertNull($result->value->comparison);
    }

    public function testMarksDynamicSchemaAndAlpsSelectorsAsUnsupported(): void
    {
        $result = (new ContractComparisonQuery())->compareInWorkspace(
            $this->workspace(),
            'app://self/user',
            'onPatch',
            SchemaQuery::KIND_REQUEST,
        );

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ContractComparison::class, $result->value);
        self::assertSame(
            [SemanticStatus::Ok, SemanticStatus::Unsupported, SemanticStatus::Unsupported],
            array_map(static fn ($surface): SemanticStatus => $surface->status, $result->value->surfaces),
        );
        self::assertNull($result->value->comparison);
    }

    public function testRejectsInvalidComparisonInput(): void
    {
        $query = new ContractComparisonQuery();

        self::assertSame(
            SemanticStatus::InvalidInput,
            $query->compareInWorkspace($this->workspace(), 'app://self/user', '', 'response')->status,
        );
        self::assertSame(
            SemanticStatus::InvalidInput,
            $query->compareInWorkspace($this->workspace(), 'app://self/user', 'onGet', 'other')->status,
        );
    }

    private function workspace(): WorkspaceContext
    {
        $root = realpath(dirname(__DIR__, 3) . '/Fixture/Contract');
        self::assertNotFalse($root);
        $result = WorkspaceContext::fromRoot($root);
        self::assertInstanceOf(WorkspaceContext::class, $result->value);

        return $result->value;
    }
}
