<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit;

use Suzumaze\BearPhpactor\Alps\AlpsDefinitionLocator;
use Suzumaze\BearPhpactor\Alps\AlpsDescriptorAtOffset;
use Suzumaze\BearPhpactor\Alps\AlpsReferenceFinder;
use Suzumaze\BearPhpactor\BearSundayExtension;
use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaConventionTypeLocator;
use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaReferenceFinder;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;
use Suzumaze\BearPhpactor\LanguageServer\ResourceInventoryIndexListener;
use Suzumaze\BearPhpactor\Resource\Completor\BodyPropertyCompletor;
use Suzumaze\BearPhpactor\Resource\Completor\ResourceUriCompletor;
use Suzumaze\BearPhpactor\Resource\ReferenceFinder\ResourceDefinitionLocator;
use Suzumaze\BearPhpactor\Resource\ReferenceFinder\ResourceReferenceFinder;
use Suzumaze\BearPhpactor\Resource\WorseReflection\ResourceClientTypeResolver;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsQuery;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsDescriptorReferencesQuery;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsProfileQuery;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectInfoQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceDescriptionQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceIncomingRelationsQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceInventoryIndex;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceInventoryQuery;
use Suzumaze\BearPhpactor\Semantic\Route\RouteQuery;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaQuery;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaReferencesQuery;
use Suzumaze\BearPhpactor\Semantic\Sql\SqlQuery;
use Suzumaze\BearPhpactor\Semantic\Sql\SqlReferencesQuery;
use Suzumaze\BearPhpactor\Sql\SqlReferenceFinder;
use Suzumaze\BearPhpactor\Semantic\Template\ResourceTemplateQuery;
use Suzumaze\BearPhpactor\Semantic\Template\TemplateQuery;
use Suzumaze\BearPhpactor\Semantic\Template\TemplateReferencesQuery;
use Suzumaze\BearPhpactor\Template\EmbedTemplateDefinitionLocator;
use Suzumaze\BearPhpactor\Template\TemplateDefinitionLocator;
use Suzumaze\BearPhpactor\Template\TemplateReferenceFinder;
use Phpactor\Container\PhpactorContainer;
use Phpactor\Extension\Completion\CompletionExtension;
use Phpactor\Extension\FilePathResolver\FilePathResolverExtension;
use Phpactor\Extension\Logger\LoggingExtension;
use Phpactor\Extension\LanguageServer\LanguageServerExtension;
use Phpactor\Extension\ReferenceFinder\ReferenceFinderExtension;
use Phpactor\Extension\WorseReflection\WorseReflectionExtension;
use PHPUnit\Framework\TestCase;

/**
 * BearSundayExtension::load() がタグ付きサービスを正しく登録することの確認。
 */
final class BearSundayExtensionTest extends TestCase
{
    public function testRegistersTransportIndependentAlpsQuery(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        self::assertInstanceOf(AlpsQuery::class, $container->get('bear_sunday.semantic.alps_query'));
        self::assertInstanceOf(
            AlpsProfileQuery::class,
            $container->get('bear_sunday.semantic.alps_profile_query'),
        );
        self::assertInstanceOf(
            AlpsFactsQuery::class,
            $container->get('bear_sunday.semantic.alps_facts_query'),
        );
        self::assertInstanceOf(
            AlpsDescriptorAtOffset::class,
            $container->get('bear_sunday.alps.descriptor_at_offset'),
        );
        self::assertInstanceOf(
            AlpsDescriptorReferencesQuery::class,
            $container->get('bear_sunday.semantic.alps_descriptor_references_query'),
        );
    }

    public function testRegistersSemanticHoverMiddleware(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        self::assertArrayHasKey(
            'bear_sunday.language_server.hover_middleware',
            $container->getServiceIdsForTag(LanguageServerExtension::TAG_MIDDLEWARE),
        );
        self::assertArrayHasKey(
            'bear_sunday.language_server.hover_middleware',
            $container->getServiceIdsForTag(LanguageServerExtension::TAG_METHOD_HANDLER),
        );
    }

    public function testRegistersTransportIndependentResourceQuery(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        self::assertInstanceOf(ResourceQuery::class, $container->get('bear_sunday.semantic.resource_query'));
    }

    public function testRegistersTransportIndependentProjectInfoQuery(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        self::assertInstanceOf(
            ProjectInfoQuery::class,
            $container->get('bear_sunday.semantic.project_info_query'),
        );
    }

    public function testRegistersTransportIndependentResourceInventoryQuery(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        self::assertInstanceOf(
            ResourceInventoryQuery::class,
            $container->get('bear_sunday.semantic.resource_inventory_query'),
        );
        $index = $container->get('bear_sunday.semantic.resource_inventory_index');
        self::assertInstanceOf(ResourceInventoryIndex::class, $index);
        self::assertFalse($index->enabled());
    }

    public function testRegistersResourceInventoryInvalidationListener(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        self::assertInstanceOf(
            ResourceInventoryIndexListener::class,
            $container->get('bear_sunday.language_server.resource_inventory_index_listener'),
        );
        self::assertArrayHasKey(
            'bear_sunday.language_server.resource_inventory_index_listener',
            $container->getServiceIdsForTag(LanguageServerExtension::TAG_LISTENER_PROVIDER),
        );
    }

    public function testRegistersTransportIndependentResourceFactsQuery(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        self::assertInstanceOf(
            ResourceFactsQuery::class,
            $container->get('bear_sunday.semantic.resource_facts_query'),
        );
    }

    public function testRegistersTransportIndependentResourceIncomingRelationsQuery(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        self::assertInstanceOf(
            ResourceIncomingRelationsQuery::class,
            $container->get('bear_sunday.semantic.resource_incoming_relations_query'),
        );
    }

    public function testRegistersTransportIndependentResourceDescriptionQuery(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        self::assertInstanceOf(
            ResourceDescriptionQuery::class,
            $container->get('bear_sunday.semantic.resource_description_query'),
        );
    }

    public function testRegistersTransportIndependentRouteQuery(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        self::assertInstanceOf(RouteQuery::class, $container->get('bear_sunday.semantic.route_query'));
    }

    public function testRegistersTransportIndependentSqlQuery(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        self::assertInstanceOf(SqlQuery::class, $container->get('bear_sunday.semantic.sql_query'));
        self::assertInstanceOf(
            SqlReferencesQuery::class,
            $container->get('bear_sunday.semantic.sql_references_query'),
        );
    }

    public function testRegistersTransportIndependentSchemaQuery(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        self::assertInstanceOf(SchemaQuery::class, $container->get('bear_sunday.semantic.schema_query'));
        self::assertInstanceOf(
            SchemaReferencesQuery::class,
            $container->get('bear_sunday.semantic.schema_references_query'),
        );
    }

    public function testRegistersTransportIndependentSchemaFactsQuery(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        self::assertInstanceOf(
            SchemaFactsQuery::class,
            $container->get('bear_sunday.semantic.schema_facts_query'),
        );
    }

    public function testRegistersTransportIndependentTemplateQuery(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        self::assertInstanceOf(TemplateQuery::class, $container->get('bear_sunday.semantic.template_query'));
        self::assertInstanceOf(
            TemplateReferencesQuery::class,
            $container->get('bear_sunday.semantic.template_references_query'),
        );
    }

    public function testRegistersTransportIndependentResourceTemplateQuery(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        self::assertInstanceOf(
            ResourceTemplateQuery::class,
            $container->get('bear_sunday.semantic.resource_template_query'),
        );
    }

    public function testRegistersDefinitionLocatorWithTag(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        $locator = $container->get('bear_sunday.resource.definition_locator');
        self::assertInstanceOf(ResourceDefinitionLocator::class, $locator);
        self::assertArrayHasKey(
            'bear_sunday.resource.definition_locator',
            $container->getServiceIdsForTag(ReferenceFinderExtension::TAG_DEFINITION_LOCATOR),
        );
    }

    public function testRegistersUriCompletorWithTag(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        $completor = $container->get('bear_sunday.resource.uri_completor');
        self::assertInstanceOf(ResourceUriCompletor::class, $completor);
        self::assertArrayHasKey(
            'bear_sunday.resource.uri_completor',
            $container->getServiceIdsForTag(CompletionExtension::TAG_COMPLETOR),
        );
    }

    public function testRegistersReferenceFinderWithTag(): void
    {
        $container = PhpactorContainer::fromExtensions([
            BearSundayExtension::class,
            FilePathResolverExtension::class,
            LoggingExtension::class,
        ]);

        $finder = $container->get('bear_sunday.resource.reference_finder');
        self::assertInstanceOf(ResourceReferenceFinder::class, $finder);
        self::assertArrayHasKey(
            'bear_sunday.resource.reference_finder',
            $container->getServiceIdsForTag(ReferenceFinderExtension::TAG_REFERENCE_FINDER),
        );
        self::assertInstanceOf(SqlReferenceFinder::class, $container->get('bear_sunday.sql.reference_finder'));
        self::assertArrayHasKey(
            'bear_sunday.sql.reference_finder',
            $container->getServiceIdsForTag(ReferenceFinderExtension::TAG_REFERENCE_FINDER),
        );
        self::assertInstanceOf(
            JsonSchemaReferenceFinder::class,
            $container->get('bear_sunday.json_schema.reference_finder'),
        );
        self::assertArrayHasKey(
            'bear_sunday.json_schema.reference_finder',
            $container->getServiceIdsForTag(ReferenceFinderExtension::TAG_REFERENCE_FINDER),
        );
        self::assertInstanceOf(AlpsReferenceFinder::class, $container->get('bear_sunday.alps.reference_finder'));
        self::assertArrayHasKey(
            'bear_sunday.alps.reference_finder',
            $container->getServiceIdsForTag(ReferenceFinderExtension::TAG_REFERENCE_FINDER),
        );
        self::assertInstanceOf(
            TemplateReferenceFinder::class,
            $container->get('bear_sunday.template.reference_finder'),
        );
        self::assertArrayHasKey(
            'bear_sunday.template.reference_finder',
            $container->getServiceIdsForTag(ReferenceFinderExtension::TAG_REFERENCE_FINDER),
        );
    }

    public function testRegistersBodyPropertyCompletorWithTag(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        $completor = $container->get('bear_sunday.completor.body_property');
        self::assertInstanceOf(BodyPropertyCompletor::class, $completor);
        self::assertArrayHasKey(
            'bear_sunday.completor.body_property',
            $container->getServiceIdsForTag(CompletionExtension::TAG_COMPLETOR),
        );
    }

    public function testRegistersAlpsDefinitionLocatorWithTag(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        $locator = $container->get(AlpsDefinitionLocator::class);
        self::assertInstanceOf(AlpsDefinitionLocator::class, $locator);
        self::assertArrayHasKey(
            AlpsDefinitionLocator::class,
            $container->getServiceIdsForTag(ReferenceFinderExtension::TAG_DEFINITION_LOCATOR),
        );
    }

    public function testRegistersEmbedTemplateDefinitionLocatorWithTag(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        $locator = $container->get(EmbedTemplateDefinitionLocator::class);
        self::assertInstanceOf(EmbedTemplateDefinitionLocator::class, $locator);
        self::assertArrayHasKey(
            EmbedTemplateDefinitionLocator::class,
            $container->getServiceIdsForTag(ReferenceFinderExtension::TAG_DEFINITION_LOCATOR),
        );
    }

    public function testRegistersJsonSchemaConventionTypeLocatorWithTag(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        $locator = $container->get('bear_sunday.reference_finder.json_schema_convention_type_locator');
        self::assertInstanceOf(JsonSchemaConventionTypeLocator::class, $locator);
        self::assertArrayHasKey(
            'bear_sunday.reference_finder.json_schema_convention_type_locator',
            $container->getServiceIdsForTag(ReferenceFinderExtension::TAG_TYPE_LOCATOR),
        );
    }

    public function testRegistersTemplateDefinitionLocatorWithTag(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        $locator = $container->get(TemplateDefinitionLocator::class);
        self::assertInstanceOf(TemplateDefinitionLocator::class, $locator);
        self::assertArrayHasKey(
            TemplateDefinitionLocator::class,
            $container->getServiceIdsForTag(ReferenceFinderExtension::TAG_DEFINITION_LOCATOR),
        );
    }

    public function testRegistersResourceClientTypeResolverWithTag(): void
    {
        // 型リゾルバは %project_root% を FilePathResolver から引くため、
        // FilePathResolverExtension (とそのロガー依存) も一緒に積む。
        $container = PhpactorContainer::fromExtensions([
            BearSundayExtension::class,
            FilePathResolverExtension::class,
            LoggingExtension::class,
        ]);

        $resolver = $container->get('bear_sunday.worse_reflection.resource_client_type_resolver');
        self::assertInstanceOf(ResourceClientTypeResolver::class, $resolver);
        self::assertArrayHasKey(
            'bear_sunday.worse_reflection.resource_client_type_resolver',
            $container->getServiceIdsForTag(WorseReflectionExtension::TAG_MEMBER_TYPE_RESOLVER),
        );
    }

    public function testRegistersSemanticQueryLspHandlerWithTag(): void
    {
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        self::assertArrayHasKey(
            'bear_sunday.language_server.semantic_query_handler',
            $container->getServiceIdsForTag(LanguageServerExtension::TAG_METHOD_HANDLER),
        );
    }
}
