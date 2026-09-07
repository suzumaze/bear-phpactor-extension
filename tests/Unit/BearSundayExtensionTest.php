<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit;

use Suzumaze\BearPhpactor\Alps\AlpsDefinitionLocator;
use Suzumaze\BearPhpactor\BearSundayExtension;
use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaConventionTypeLocator;
use Suzumaze\BearPhpactor\Resource\Completor\BodyPropertyCompletor;
use Suzumaze\BearPhpactor\Resource\Completor\ResourceUriCompletor;
use Suzumaze\BearPhpactor\Resource\ReferenceFinder\ResourceDefinitionLocator;
use Suzumaze\BearPhpactor\Resource\ReferenceFinder\ResourceReferenceFinder;
use Suzumaze\BearPhpactor\Resource\WorseReflection\ResourceClientTypeResolver;
use Suzumaze\BearPhpactor\Template\EmbedTemplateDefinitionLocator;
use Phpactor\Container\PhpactorContainer;
use Phpactor\Extension\Completion\CompletionExtension;
use Phpactor\Extension\FilePathResolver\FilePathResolverExtension;
use Phpactor\Extension\Logger\LoggingExtension;
use Phpactor\Extension\ReferenceFinder\ReferenceFinderExtension;
use Phpactor\Extension\WorseReflection\WorseReflectionExtension;
use PHPUnit\Framework\TestCase;

/**
 * BearSundayExtension::load() がタグ付きサービスを正しく登録することの確認。
 */
final class BearSundayExtensionTest extends TestCase
{
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
        $container = PhpactorContainer::fromExtensions([BearSundayExtension::class]);

        $finder = $container->get('bear_sunday.resource.reference_finder');
        self::assertInstanceOf(ResourceReferenceFinder::class, $finder);
        self::assertArrayHasKey(
            'bear_sunday.resource.reference_finder',
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
}
