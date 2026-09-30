<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Di;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Di\DiBindingFact;
use Suzumaze\BearPhpactor\Semantic\Di\DiBindingInventory;
use Suzumaze\BearPhpactor\Semantic\Di\DiBindingQuery;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

final class DiBindingQueryTest extends TestCase
{
    public function testReadsEveryRayDiBindFormAndKeepsKnownPartsOfUnresolvedDeclarations(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->fixture());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = (new DiBindingQuery())->listInWorkspace($workspace->value, limit: 100);

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(DiBindingInventory::class, $result->value);
        self::assertSame(30, $result->value->total);
        self::assertSame(3, $result->value->scannedModules);
        self::assertSame(16, $result->value->unresolved);
        // [kind, sourceType, qualifier, scope, targetType, valueType, reason] without the fixture namespace.
        self::assertSame([
            ['class', 'ClockInterface', null, null, 'Clock', null, null],
            ['class', null, null, null, 'DynamicService', null, 'binding_source_not_static'],
            ['class', 'ClockInterface', 'primary', null, 'Clock', null, null],
            ['class', 'LoggerInterface', 'Annotation\\Primary', 'singleton', 'FileLogger', null, null],
            ['provider', 'ConnectionInterface', null, 'prototype', 'ConnectionProvider', null, null],
            ['provider', 'ConnectionInterface', 'write', null, 'ConnectionProvider', null, null],
            ['untargeted', 'Mailer', null, 'singleton', null, null, null],
            ['constructor', 'Mailer', 'smtp', null, 'Mailer', null, null],
            ['constructor', 'Mailer', 'queue', null, 'Mailer', null, null],
            ['null', 'NotifierInterface', null, null, null, null, null],
            ['instance', '', 'app_name', null, null, 'string', null],
            ['instance', '', 'retry', null, null, 'integer', null],
            ['instance', '', 'hosts', null, null, 'array', null],
            ['instance', 'Clock', null, null, null, 'object', null],
            ['instance', '', 'config', null, null, null, null],
            ['class', 'Clock', null, null, 'Clock', null, 'binding_chain_unsupported'],
            ['null', 'LoggerInterface', null, null, null, null, 'binding_multiple_targets'],
            ['class', 'LoggerInterface', 'b', null, 'FileLogger', null, 'binding_operation_repeated'],
            ['class', 'LoggerInterface', 'late', null, 'FileLogger', null, 'binding_qualifier_after_target'],
            ['class', 'LoggerInterface', null, 'singleton', 'FileLogger', null, 'binding_scope_before_target'],
            ['null', 'NotifierInterface', null, null, null, null, 'binding_arguments_unsupported'],
            ['class', 'LoggerInterface', null, null, 'FileLogger', null, 'binding_qualifier_not_static'],
            ['class', 'LoggerInterface', null, null, 'FileLogger', null, 'binding_scope_not_static'],
            ['class', 'LoggerInterface', null, null, 'FileLogger', null, 'binding_scope_unknown'],
            ['class', 'LoggerInterface', null, null, null, null, 'binding_target_not_static'],
            [
                'provider',
                'ConnectionInterface',
                null,
                null,
                'ConnectionProvider',
                null,
                'binding_provider_context_not_static',
            ],
            ['constructor', 'Mailer', null, null, 'Mailer', null, 'binding_constructor_arguments_not_static'],
            ['constructor', 'Mailer', null, null, 'Mailer', null, 'binding_constructor_injection_points_not_static'],
            ['constructor', 'Mailer', null, null, 'Mailer', null, 'binding_constructor_post_construct_not_static'],
            ['untargeted', '', 'orphan', null, null, null, 'binding_untargeted_type_missing'],
        ], array_map(static fn (DiBindingFact $item): array => array_map(
            static fn (?string $value): ?string => $value === null
                ? null
                : str_replace(['Acme\\DiAop\\Service\\', 'Acme\\DiAop\\'], '', $value),
            [
                $item->kind,
                $item->sourceType,
                $item->qualifier,
                $item->scope,
                $item->targetType,
                $item->valueType,
                $item->reason,
            ],
        ), $result->value->items));
        foreach ($result->value->items as $item) {
            self::assertSame(
                $item->reason === null ? DiBindingFact::STATE_RESOLVED : DiBindingFact::STATE_UNRESOLVED,
                $item->state,
            );
        }
        self::assertSame(
            ['src/Module/AppModule.php', 'src/Module/FeatureModule.php'],
            array_values(array_unique(array_column($result->value->items, 'path'))),
        );
    }

    public function testDoesNotExposeBindingExpressionText(): void
    {
        $handler = new \Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler($this->fixture('DiComposition'));
        $response = \Amp\Promise\wait($handler->inspectDiBindings(limit: 100));

        self::assertSame('ok', $response['status']);
        $serialized = json_encode($response['data']['items'], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('targetExpression', $serialized);
        self::assertStringNotContainsString('constructorArguments', $serialized);
        self::assertStringNotContainsString('fixture-private-value', $serialized);
        $secretBinding = array_values(array_filter(
            $response['data']['items'],
            static fn (array $item): bool => $item['qualifier'] === 'api-key',
        ))[0];
        self::assertSame('instance', $secretBinding['kind']);
        self::assertSame('string', $secretBinding['valueType']);
    }

    public function testFiltersByExactSourceTypeAndValidatesPagination(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->fixture());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);
        $query = new DiBindingQuery();

        $result = $query->listInWorkspace(
            $workspace->value,
            'Acme\\DiAop\\Service\\ClockInterface',
            limit: 1,
        );

        self::assertInstanceOf(DiBindingInventory::class, $result->value);
        self::assertSame(2, $result->value->total);
        self::assertCount(1, $result->value->items);
        self::assertTrue($result->value->truncated);
        self::assertSame(
            SemanticStatus::InvalidInput,
            $query->listInWorkspace($workspace->value, limit: 101)->status,
        );
    }

    public function testScopesBindingsToApplicationContextModules(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->fixture());
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);
        $query = new DiBindingQuery();

        $result = $query->listInWorkspace($workspace->value, applicationContext: 'app');
        self::assertInstanceOf(DiBindingInventory::class, $result->value);
        self::assertSame(30, $result->value->total);
        self::assertSame(2, $result->value->scannedModules);
        self::assertSame(
            SemanticStatus::InvalidInput,
            $query->listInWorkspace($workspace->value, applicationContext: '')->status,
        );
    }

    public function testTreatsInterpolatedStringsAsNotStatic(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->fixture('DiAopInterpolation'));
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = (new DiBindingQuery())->listInWorkspace($workspace->value);

        self::assertInstanceOf(DiBindingInventory::class, $result->value);
        self::assertSame([
            'binding_qualifier_not_static',
            'binding_qualifier_not_static',
            'binding_source_not_static',
            null,
            null,
            null,
        ], array_column($result->value->items, 'reason'));
        self::assertSame('plain_name', $result->value->items[4]->qualifier);
        self::assertSame('single_$name', $result->value->items[5]->qualifier);
    }

    public function testRetainedBindChainIsNotReportedAsResolved(): void
    {
        $workspace = WorkspaceContext::fromRoot($this->fixture('DiAopRetainedBind'));
        self::assertInstanceOf(WorkspaceContext::class, $workspace->value);

        $result = (new DiBindingQuery())->listInWorkspace($workspace->value);

        self::assertInstanceOf(DiBindingInventory::class, $result->value);
        // A qualifier added through the variable is not claimed; direct and conditional chains stay resolved.
        self::assertSame([
            [DiBindingFact::STATE_UNRESOLVED, 'binding_chain_retained', null],
            [DiBindingFact::STATE_RESOLVED, null, 'direct'],
            [DiBindingFact::STATE_RESOLVED, null, null],
        ], array_map(
            static fn (DiBindingFact $item): array => [$item->state, $item->reason, $item->qualifier],
            $result->value->items,
        ));
    }

    private function fixture(string $name = 'DiAop'): string
    {
        $fixture = realpath(dirname(__DIR__, 3) . '/Fixture/' . $name);
        self::assertNotFalse($fixture);

        return $fixture;
    }
}
