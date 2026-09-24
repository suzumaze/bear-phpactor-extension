<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor;

use Suzumaze\BearPhpactor\Alps\AlpsDefinitionLocator;
use Suzumaze\BearPhpactor\Alps\AlpsDescriptorAtOffset;
use Suzumaze\BearPhpactor\Alps\AlpsReferenceFinder;
use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaConventionTypeLocator;
use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaReferenceAtOffset;
use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaReferenceFinder;
use Suzumaze\BearPhpactor\LanguageServer\BearDiagnosticsProvider;
use Suzumaze\BearPhpactor\LanguageServer\BearHoverMiddleware;
use Suzumaze\BearPhpactor\LanguageServer\ResourceInventoryIndexListener;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;
use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaDefinitionLocator;
use Suzumaze\BearPhpactor\Resource\Completor\BodyPropertyCompletor;
use Suzumaze\BearPhpactor\Resource\Completor\ResourceUriCompletor;
use Suzumaze\BearPhpactor\Resource\LanguageServer\ResourceUriDocumentLinkHandler;
use Suzumaze\BearPhpactor\Resource\Model\ResourceTargetResolver;
use Suzumaze\BearPhpactor\Resource\ReferenceFinder\ResourceDefinitionLocator;
use Suzumaze\BearPhpactor\Resource\ReferenceFinder\ResourceReferenceFinder;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Resource\WorseReflection\ResourceClientTypeResolver;
use Suzumaze\BearPhpactor\Router\RouterDefinitionLocator;
use Suzumaze\BearPhpactor\Router\RouteReferenceAtOffset;
use Suzumaze\BearPhpactor\Semantic\Aop\AopPointcutQuery;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsFactsQuery;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsDescriptorReferencesQuery;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsProfileQuery;
use Suzumaze\BearPhpactor\Semantic\Alps\AlpsQuery;
use Suzumaze\BearPhpactor\Semantic\Contract\ContractComparisonQuery;
use Suzumaze\BearPhpactor\Semantic\Di\DiBindingQuery;
use Suzumaze\BearPhpactor\Semantic\Project\ContractCoverageQuery;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectDiagnosticsQuery;
use Suzumaze\BearPhpactor\Semantic\Project\ProjectInfoQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceAttributeIndexQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceDescriptionQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceReferencesQuery;
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
use Suzumaze\BearPhpactor\Semantic\Template\ResourceTemplateQuery;
use Suzumaze\BearPhpactor\Semantic\Template\TemplateQuery;
use Suzumaze\BearPhpactor\Semantic\Template\TemplateReferencesQuery;
use Suzumaze\BearPhpactor\Semantic\Template\TemplateSourceScanner;
use Suzumaze\BearPhpactor\Sql\SqlDefinitionLocator;
use Suzumaze\BearPhpactor\Sql\SqlQueryAtOffset;
use Suzumaze\BearPhpactor\Sql\SqlReferenceFinder;
use Suzumaze\BearPhpactor\Template\EmbedTemplateDefinitionLocator;
use Suzumaze\BearPhpactor\Template\TemplateDefinitionLocator;
use Suzumaze\BearPhpactor\Template\TemplateReferenceScanner;
use Suzumaze\BearPhpactor\Template\TemplateReferenceFinder;
use Phpactor\Container\Container;
use Phpactor\Container\ContainerBuilder;
use Phpactor\Container\Extension;
use Phpactor\Extension\Completion\CompletionExtension;
use Phpactor\Extension\FilePathResolver\FilePathResolverExtension;
use Phpactor\Extension\LanguageServer\LanguageServerExtension;
use Phpactor\Extension\LanguageServer\Container\DiagnosticProviderTag;
use Phpactor\Extension\ReferenceFinder\ReferenceFinderExtension;
use Phpactor\Extension\WorseReflection\WorseReflectionExtension;
use Phpactor\MapResolver\Resolver;
use Phpactor\LanguageServerProtocol\ClientCapabilities;

/**
 * BEAR.Sunday extension for phpactor.
 *
 * BEAR.Sunday の規約を phpactor に教える。v0.1〜v0.2 は規約の写像 (定義ジャンプ・
 * 補完) で、型推論は使わない。v0.3 から WorseReflection の型推論フック
 * (member_type_resolver) も1本持つ。
 * 名前空間やディレクトリの起点は、対象プロジェクトの composer.json の psr-4 から解決する。
 */
final class BearSundayExtension implements Extension
{
    public function load(ContainerBuilder $container): void
    {
        // リソースURI ('app://self/user') の定義ジャンプとURI補完。
        $container->register(
            'bear_sunday.resource.string_literal_at_offset',
            function (Container $container): StringLiteralAtOffset {
                return new StringLiteralAtOffset();
            }
        );

        // BEAR semantics are resolved outside the LSP adapters. The legacy
        // ResourceTargetResolver remains as a compatibility facade while
        // existing locators migrate to the structured semantic result.
        $container->register(
            'bear_sunday.semantic.resource_query',
            function (Container $container): ResourceQuery {
                return new ResourceQuery();
            }
        );

        $container->register(
            'bear_sunday.semantic.resource_inventory_index',
            function (Container $container): ResourceInventoryIndex {
                return new ResourceInventoryIndex(self::supportsWatchedFileInvalidation($container));
            },
        );

        $container->register(
            'bear_sunday.semantic.resource_inventory_query',
            function (Container $container): ResourceInventoryQuery {
                return new ResourceInventoryQuery(
                    $container->get('bear_sunday.semantic.resource_inventory_index'),
                );
            },
        );

        $container->register(
            'bear_sunday.semantic.resource_facts_query',
            function (Container $container): ResourceFactsQuery {
                return new ResourceFactsQuery($container->get('bear_sunday.semantic.resource_query'));
            },
        );

        $container->register(
            'bear_sunday.semantic.resource_attribute_index_query',
            function (Container $container): ResourceAttributeIndexQuery {
                return new ResourceAttributeIndexQuery(
                    $container->get('bear_sunday.semantic.resource_inventory_query'),
                    $container->get('bear_sunday.semantic.resource_facts_query'),
                );
            },
        );

        $container->register(
            'bear_sunday.semantic.contract_comparison_query',
            function (Container $container): ContractComparisonQuery {
                return new ContractComparisonQuery(
                    $container->get('bear_sunday.semantic.resource_facts_query'),
                    $container->get('bear_sunday.semantic.schema_facts_query'),
                    $container->get('bear_sunday.semantic.alps_facts_query'),
                );
            },
        );

        $container->register(
            'bear_sunday.semantic.resource_incoming_relations_query',
            function (Container $container): ResourceIncomingRelationsQuery {
                return new ResourceIncomingRelationsQuery(
                    $container->get('bear_sunday.semantic.resource_query'),
                    $container->get('bear_sunday.semantic.resource_facts_query'),
                    $container->get('bear_sunday.semantic.resource_inventory_index'),
                );
            },
        );

        $container->register(
            'bear_sunday.language_server.resource_inventory_index_listener',
            function (Container $container): ResourceInventoryIndexListener {
                return new ResourceInventoryIndexListener(
                    $container->get('bear_sunday.semantic.resource_inventory_index'),
                );
            },
            [LanguageServerExtension::TAG_LISTENER_PROVIDER => []],
        );

        $container->register(
            'bear_sunday.semantic.resource_description_query',
            function (Container $container): ResourceDescriptionQuery {
                return new ResourceDescriptionQuery(
                    $container->get('bear_sunday.semantic.resource_facts_query'),
                    $container->get('bear_sunday.semantic.resource_incoming_relations_query'),
                    $container->get('bear_sunday.semantic.resource_template_query'),
                    $container->get('bear_sunday.semantic.schema_query'),
                );
            },
        );

        $container->register(
            'bear_sunday.semantic.project_info_query',
            function (Container $container): ProjectInfoQuery {
                return new ProjectInfoQuery(
                    inventoryIndex: $container->get('bear_sunday.semantic.resource_inventory_index'),
                );
            },
        );

        $container->register(
            'bear_sunday.semantic.di_binding_query',
            function (): DiBindingQuery {
                return new DiBindingQuery();
            },
        );

        $container->register(
            'bear_sunday.semantic.aop_pointcut_query',
            function (): AopPointcutQuery {
                return new AopPointcutQuery();
            },
        );

        $container->register(
            'bear_sunday.semantic.project_diagnostics_query',
            function (Container $container): ProjectDiagnosticsQuery {
                return new ProjectDiagnosticsQuery(
                    inventoryQuery: $container->get('bear_sunday.semantic.resource_inventory_query'),
                    resourceFactsQuery: $container->get('bear_sunday.semantic.resource_facts_query'),
                    resourceQuery: $container->get('bear_sunday.semantic.resource_query'),
                    routeQuery: $container->get('bear_sunday.semantic.route_query'),
                    sqlQuery: $container->get('bear_sunday.semantic.sql_query'),
                    schemaFactsQuery: $container->get('bear_sunday.semantic.schema_facts_query'),
                    alpsQuery: $container->get('bear_sunday.semantic.alps_query'),
                    templateQuery: $container->get('bear_sunday.semantic.template_query'),
                    contractComparisonQuery: $container->get('bear_sunday.semantic.contract_comparison_query'),
                    templateSourceScanner: $container->get('bear_sunday.semantic.template_source_scanner'),
                    sqlReferenceScanner: $container->get('bear_sunday.sql.query_at_offset'),
                    schemaReferenceScanner: $container->get('bear_sunday.json_schema.reference_at_offset'),
                    alpsReferenceScanner: $container->get('bear_sunday.alps.descriptor_at_offset'),
                    routeReferenceScanner: $container->get('bear_sunday.router.reference_at_offset'),
                    templateReferenceScanner: $container->get('bear_sunday.template.reference_scanner'),
                );
            },
        );

        $container->register(
            'bear_sunday.language_server.diagnostics_provider',
            function (Container $container): BearDiagnosticsProvider {
                $pathResolver = $container->get(FilePathResolverExtension::SERVICE_FILE_PATH_RESOLVER);

                return new BearDiagnosticsProvider(
                    $pathResolver->resolve('%project_root%'),
                    $container->get('bear_sunday.semantic.project_diagnostics_query'),
                );
            },
            [
                LanguageServerExtension::TAG_DIAGNOSTICS_PROVIDER => DiagnosticProviderTag::create('bear'),
            ],
        );

        $container->register(
            'bear_sunday.semantic.contract_coverage_query',
            function (Container $container): ContractCoverageQuery {
                return new ContractCoverageQuery(
                    $container->get('bear_sunday.semantic.resource_inventory_query'),
                    $container->get('bear_sunday.semantic.resource_facts_query'),
                    $container->get('bear_sunday.semantic.contract_comparison_query'),
                );
            },
        );

        $container->register(
            'bear_sunday.resource.target_resolver',
            function (Container $container): ResourceTargetResolver {
                return new ResourceTargetResolver($container->get('bear_sunday.semantic.resource_query'));
            }
        );

        $container->register(
            'bear_sunday.resource.definition_locator',
            function (Container $container): ResourceDefinitionLocator {
                return new ResourceDefinitionLocator(
                    $container->get('bear_sunday.resource.string_literal_at_offset'),
                    $container->get('bear_sunday.resource.target_resolver'),
                );
            },
            [
                ReferenceFinderExtension::TAG_DEFINITION_LOCATOR => [],
            ]
        );

        $container->register(
            'bear_sunday.resource.uri_completor',
            function (Container $container): ResourceUriCompletor {
                return new ResourceUriCompletor(
                    $container->get('bear_sunday.resource.string_literal_at_offset'),
                    $container->get('bear_sunday.semantic.resource_inventory_index'),
                );
            },
            [
                CompletionExtension::TAG_COMPLETOR => [
                    CompletionExtension::KEY_COMPLETOR_TYPES => ['php'],
                ],
            ]
        );

        // リソースURIとテンプレート名全体を1つのリンクにする (textDocument/documentLink)。
        // 定義ジャンプ経由ではクリック範囲を指定できず、スラッシュで分割されるため。
        // phpactor はこのメソッドを未実装なので、登録しても何も置き換えない。
        $container->register(
            'bear_sunday.resource.document_link_handler',
            function (Container $container): ResourceUriDocumentLinkHandler {
                return new ResourceUriDocumentLinkHandler(
                    $container->get(LanguageServerExtension::SERVICE_SESSION_WORKSPACE),
                    $container->get('bear_sunday.resource.definition_locator'),
                    $container->get(TemplateDefinitionLocator::class),
                );
            },
            [LanguageServerExtension::TAG_METHOD_HANDLER => []]
        );

        // Twig/Qiqテンプレート内の静的なテンプレート参照を実ファイルへ解決する。
        // Twig: extends/include/include()/block()第2引数。
        // Qiq: setLayout()/render()/extends()（裸のQiq helperと$this->形式の両方）。
        $container->register('bear_sunday.semantic.template_query', function (): TemplateQuery {
            return new TemplateQuery();
        });
        $container->register(
            'bear_sunday.semantic.resource_template_query',
            function (Container $container): ResourceTemplateQuery {
                return new ResourceTemplateQuery($container->get('bear_sunday.semantic.resource_query'));
            },
        );

        $container->register(
            'bear_sunday.language_server.semantic_query_handler',
            function (Container $container): SemanticQueryHandler {
                $pathResolver = $container->get(FilePathResolverExtension::SERVICE_FILE_PATH_RESOLVER);

                return new SemanticQueryHandler(
                    $pathResolver->resolve('%project_root%'),
                    $container->get('bear_sunday.semantic.resource_query'),
                    $container->get('bear_sunday.semantic.route_query'),
                    $container->get('bear_sunday.semantic.sql_query'),
                    $container->get('bear_sunday.semantic.template_query'),
                    $container->get('bear_sunday.semantic.resource_template_query'),
                    $container->get('bear_sunday.semantic.alps_query'),
                    $container->get('bear_sunday.semantic.schema_query'),
                    $container->get('bear_sunday.semantic.resource_inventory_query'),
                    $container->get('bear_sunday.semantic.resource_facts_query'),
                    $container->get('bear_sunday.semantic.resource_incoming_relations_query'),
                    $container->get('bear_sunday.semantic.resource_description_query'),
                    $container->get('bear_sunday.semantic.project_info_query'),
                    $container->get('bear_sunday.semantic.schema_facts_query'),
                    $container->get('bear_sunday.semantic.alps_facts_query'),
                    $container->get('bear_sunday.semantic.resource_references_query'),
                    $container->get('bear_sunday.semantic.resource_attribute_index_query'),
                    $container->get('bear_sunday.semantic.contract_comparison_query'),
                    $container->get('bear_sunday.semantic.project_diagnostics_query'),
                    $container->get('bear_sunday.semantic.contract_coverage_query'),
                    $container->get('bear_sunday.semantic.di_binding_query'),
                    $container->get('bear_sunday.semantic.aop_pointcut_query'),
                );
            },
            [LanguageServerExtension::TAG_METHOD_HANDLER => []],
        );

        $container->register(
            'bear_sunday.language_server.hover_middleware',
            function (Container $container): BearHoverMiddleware {
                $pathResolver = $container->get(FilePathResolverExtension::SERVICE_FILE_PATH_RESOLVER);

                return new BearHoverMiddleware(
                    $container->get(LanguageServerExtension::SERVICE_SESSION_WORKSPACE),
                    $pathResolver->resolve('%project_root%'),
                    $container->get('bear_sunday.resource.string_literal_at_offset'),
                    $container->get('bear_sunday.semantic.resource_facts_query'),
                    $container->get('bear_sunday.alps.descriptor_at_offset'),
                    $container->get('bear_sunday.semantic.alps_facts_query'),
                    $container->get('bear_sunday.template.reference_scanner'),
                    $container->get('bear_sunday.semantic.template_query'),
                    $container->get('bear_sunday.json_schema.reference_at_offset'),
                    $container->get('bear_sunday.semantic.schema_facts_query'),
                    $container->get('bear_sunday.sql.query_at_offset'),
                    $container->get('bear_sunday.semantic.sql_query'),
                    $container->get('bear_sunday.router.reference_at_offset'),
                    $container->get('bear_sunday.semantic.route_query'),
                );
            },
            [
                LanguageServerExtension::TAG_MIDDLEWARE => [],
                LanguageServerExtension::TAG_METHOD_HANDLER => [],
            ],
        );

        $container->register(
            TemplateDefinitionLocator::class,
            function (Container $container): TemplateDefinitionLocator {
                return new TemplateDefinitionLocator(
                    scanner: $container->get('bear_sunday.template.reference_scanner'),
                    query: $container->get('bear_sunday.semantic.template_query'),
                );
            },
            [ReferenceFinderExtension::TAG_DEFINITION_LOCATOR => []]
        );

        $container->register(
            'bear_sunday.template.reference_scanner',
            function (): TemplateReferenceScanner {
                return new TemplateReferenceScanner();
            },
        );
        $container->register(
            'bear_sunday.semantic.template_source_scanner',
            function (): TemplateSourceScanner {
                return new TemplateSourceScanner();
            },
        );
        $container->register(
            'bear_sunday.semantic.template_references_query',
            function (Container $container): TemplateReferencesQuery {
                return new TemplateReferencesQuery(
                    $container->get('bear_sunday.semantic.template_query'),
                    $container->get('bear_sunday.template.reference_scanner'),
                    $container->get('bear_sunday.semantic.template_source_scanner'),
                );
            },
        );
        $container->register(
            'bear_sunday.template.reference_finder',
            function (Container $container): TemplateReferenceFinder {
                $pathResolver = $container->get(FilePathResolverExtension::SERVICE_FILE_PATH_RESOLVER);

                return new TemplateReferenceFinder(
                    $container->get('bear_sunday.template.reference_scanner'),
                    $container->get('bear_sunday.semantic.template_references_query'),
                    $pathResolver->resolve('%project_root%'),
                );
            },
            [ReferenceFinderExtension::TAG_REFERENCE_FINDER => []],
        );

        $container->register('bear_sunday.semantic.route_query', function (Container $container): RouteQuery {
            return new RouteQuery(
                $container->get('bear_sunday.resource.target_resolver'),
                $container->get('bear_sunday.semantic.resource_query'),
            );
        });
        $container->register(
            'bear_sunday.router.reference_at_offset',
            function (Container $container): RouteReferenceAtOffset {
                return new RouteReferenceAtOffset(
                    $container->get('bear_sunday.resource.string_literal_at_offset'),
                );
            },
        );
        $container->register(
            'bear_sunday.semantic.resource_references_query',
            function (Container $container): ResourceReferencesQuery {
                return new ResourceReferencesQuery(
                    $container->get('bear_sunday.semantic.resource_query'),
                    $container->get('bear_sunday.semantic.route_query'),
                    $container->get('bear_sunday.router.reference_at_offset'),
                );
            },
        );

        // Aura.Router: aura.route.php のルート名から Page リソースクラスへの定義ジャンプ。
        $container->register(RouterDefinitionLocator::class, function (Container $container) {
            return new RouterDefinitionLocator(
                resourceTargetResolver: $container->get('bear_sunday.resource.target_resolver'),
                routeQuery: $container->get('bear_sunday.semantic.route_query'),
                routeReferenceAtOffset: $container->get('bear_sunday.router.reference_at_offset'),
            );
        }, [ReferenceFinderExtension::TAG_DEFINITION_LOCATOR => []]);

        $container->register('bear_sunday.semantic.sql_query', function (): SqlQuery {
            return new SqlQuery();
        });
        $container->register(
            'bear_sunday.sql.query_at_offset',
            function (Container $container): SqlQueryAtOffset {
                return new SqlQueryAtOffset(
                    $container->get('bear_sunday.resource.string_literal_at_offset'),
                );
            },
        );
        $container->register(
            'bear_sunday.semantic.sql_references_query',
            function (Container $container): SqlReferencesQuery {
                return new SqlReferencesQuery(
                    $container->get('bear_sunday.semantic.sql_query'),
                    $container->get('bear_sunday.sql.query_at_offset'),
                );
            },
        );

        // SQL定義ジャンプ: #[DbQuery('...')] / @Query("...") → var/db/sql/<名前>.sql
        $container->register(SqlDefinitionLocator::class, function (Container $container): SqlDefinitionLocator {
            return new SqlDefinitionLocator(
                sqlQuery: $container->get('bear_sunday.semantic.sql_query'),
                sqlQueryAtOffset: $container->get('bear_sunday.sql.query_at_offset'),
            );
        }, [
            ReferenceFinderExtension::TAG_DEFINITION_LOCATOR => [],
        ]);

        // SQL参照検索: 同じ実在SQLファイルへ解決する DbQuery / @Query を列挙する。
        // false で鎖を続け、Phpactor組込みの参照検索を妨げない。
        $container->register(
            'bear_sunday.sql.reference_finder',
            function (Container $container): SqlReferenceFinder {
                $pathResolver = $container->get(FilePathResolverExtension::SERVICE_FILE_PATH_RESOLVER);

                return new SqlReferenceFinder(
                    $container->get('bear_sunday.sql.query_at_offset'),
                    $container->get('bear_sunday.semantic.sql_references_query'),
                    $pathResolver->resolve('%project_root%'),
                );
            },
            [ReferenceFinderExtension::TAG_REFERENCE_FINDER => []],
        );

        $container->register('bear_sunday.semantic.schema_query', function (Container $container): SchemaQuery {
            return new SchemaQuery(
                resourceQuery: $container->get('bear_sunday.semantic.resource_query'),
            );
        });

        $container->register(
            'bear_sunday.json_schema.reference_at_offset',
            function (Container $container): JsonSchemaReferenceAtOffset {
                return new JsonSchemaReferenceAtOffset(
                    $container->get('bear_sunday.resource.string_literal_at_offset'),
                );
            },
        );

        $container->register(
            'bear_sunday.semantic.schema_references_query',
            function (Container $container): SchemaReferencesQuery {
                return new SchemaReferencesQuery(
                    $container->get('bear_sunday.semantic.schema_query'),
                    $container->get('bear_sunday.json_schema.reference_at_offset'),
                );
            },
        );

        $container->register(
            'bear_sunday.semantic.schema_facts_query',
            function (Container $container): SchemaFactsQuery {
                return new SchemaFactsQuery($container->get('bear_sunday.semantic.schema_query'));
            },
        );

        // JsonSchema: #[JsonSchema('user.json')] 属性からスキーマファイルへ定義ジャンプ。
        // プロジェクトルートは他の3機能と同じく「ドキュメントの位置から上へ composer.json を辿る」方式
        // (ProjectLocator) で、LSPワークスペースの %project_root% には依存しない。
        $container->register(
            'bear_sunday.reference_finder.json_schema_definition_locator',
            function (Container $container) {
                return new JsonSchemaDefinitionLocator(
                    $container->get('bear_sunday.resource.string_literal_at_offset'),
                    schemaQuery: $container->get('bear_sunday.semantic.schema_query'),
                    referenceAtOffset: $container->get('bear_sunday.json_schema.reference_at_offset'),
                );
            },
            [ReferenceFinderExtension::TAG_DEFINITION_LOCATOR => []]
        );

        // 明示JsonSchema参照検索: 同じ実在Schemaファイルへ解決する属性引数を列挙する。
        $container->register(
            'bear_sunday.json_schema.reference_finder',
            function (Container $container): JsonSchemaReferenceFinder {
                $pathResolver = $container->get(FilePathResolverExtension::SERVICE_FILE_PATH_RESOLVER);

                return new JsonSchemaReferenceFinder(
                    $container->get('bear_sunday.json_schema.reference_at_offset'),
                    $container->get('bear_sunday.semantic.schema_references_query'),
                    $pathResolver->resolve('%project_root%'),
                );
            },
            [ReferenceFinderExtension::TAG_REFERENCE_FINDER => []],
        );

        // JsonSchema 規約ジャンプ (クラス宣言名 → var/json_schema/<ケバブ>.json) は
        // 定義ジャンプではなく型定義ジャンプ (textDocument/typeDefinition) に載せる。
        // クラス宣言名の上のF12は VS Code の慣習では「その場に留まる」で、定義ジャンプを
        // 上書きすると Shift の押し間違い (⇧F12 のつもり) が「壊れている」ように見える
        // (PLAN.md §2.6 の②の退避先)。該当しないときは空を返し、組込みの型定義解決
        // (WorseReflectionTypeLocator) まで鎖を続ける。
        $container->register(
            'bear_sunday.reference_finder.json_schema_convention_type_locator',
            function (Container $container): JsonSchemaConventionTypeLocator {
                return new JsonSchemaConventionTypeLocator(
                    schemaQuery: $container->get('bear_sunday.semantic.schema_query'),
                );
            },
            [ReferenceFinderExtension::TAG_TYPE_LOCATOR => []]
        );

        $container->register('bear_sunday.semantic.alps_profile_query', function (): AlpsProfileQuery {
            return new AlpsProfileQuery();
        });
        $container->register('bear_sunday.semantic.alps_query', function (Container $container): AlpsQuery {
            return new AlpsQuery($container->get('bear_sunday.semantic.alps_profile_query'));
        });
        $container->register('bear_sunday.semantic.alps_facts_query', function (Container $container): AlpsFactsQuery {
            return new AlpsFactsQuery($container->get('bear_sunday.semantic.alps_profile_query'));
        });
        $container->register(
            'bear_sunday.alps.descriptor_at_offset',
            function (Container $container): AlpsDescriptorAtOffset {
                return new AlpsDescriptorAtOffset(
                    $container->get('bear_sunday.resource.string_literal_at_offset'),
                );
            },
        );
        $container->register(
            'bear_sunday.semantic.alps_descriptor_references_query',
            function (Container $container): AlpsDescriptorReferencesQuery {
                return new AlpsDescriptorReferencesQuery(
                    $container->get('bear_sunday.semantic.alps_query'),
                    $container->get('bear_sunday.alps.descriptor_at_offset'),
                );
            },
        );

        // ALPSプロファイル: #[Alps('doDeleteArticle')] 属性から profile.json の
        // 記述子定義へ定義ジャンプ。プロファイルの場所は固定の規約パスでは無く、
        // プロジェクトルート直下の apidoc.xml の <alps> 要素で指定される。
        $container->register(AlpsDefinitionLocator::class, function (Container $container): AlpsDefinitionLocator {
            return new AlpsDefinitionLocator(
                stringLiteralAtOffset: $container->get('bear_sunday.resource.string_literal_at_offset'),
                alpsQuery: $container->get('bear_sunday.semantic.alps_query'),
                descriptorAtOffset: $container->get('bear_sunday.alps.descriptor_at_offset'),
            );
        }, [
            ReferenceFinderExtension::TAG_DEFINITION_LOCATOR => [],
        ]);

        // ALPS参照検索: 同じprofile内の一意なdescriptorを指す属性利用箇所を列挙する。
        $container->register(
            'bear_sunday.alps.reference_finder',
            function (Container $container): AlpsReferenceFinder {
                $pathResolver = $container->get(FilePathResolverExtension::SERVICE_FILE_PATH_RESOLVER);

                return new AlpsReferenceFinder(
                    $container->get('bear_sunday.alps.descriptor_at_offset'),
                    $container->get('bear_sunday.semantic.alps_descriptor_references_query'),
                    $pathResolver->resolve('%project_root%'),
                );
            },
            [ReferenceFinderExtension::TAG_REFERENCE_FINDER => []],
        );

        // Twig / Qiq: Resource の #[Embed(rel, src) に対応するテンプレート変数から、
        // 埋め込み先 Resource の同種テンプレートへ定義ジャンプ。
        $container->register(
            EmbedTemplateDefinitionLocator::class,
            function (Container $container): EmbedTemplateDefinitionLocator {
                return new EmbedTemplateDefinitionLocator();
            },
            [
                ReferenceFinderExtension::TAG_DEFINITION_LOCATOR => [],
            ]
        );

        // 参照検索: Resource URI・Resourceクラス宣言名・Route名から、同じResourceを
        // 参照するURIとRoute宣言 (textDocument/references) を探す。必ず false で終わる
        // ファインダーなので、組込みの IndexedReferenceFinder (通常のPHPクラス参照
        // 検索) まで鎖は続く (ChainReferenceFinder は true で止まる)。
        $container->register(
            'bear_sunday.resource.reference_finder',
            function (Container $container): ResourceReferenceFinder {
                $pathResolver = $container->get(FilePathResolverExtension::SERVICE_FILE_PATH_RESOLVER);

                return new ResourceReferenceFinder(
                    $container->get('bear_sunday.resource.string_literal_at_offset'),
                    $container->get('bear_sunday.resource.target_resolver'),
                    routeReferenceAtOffset: $container->get('bear_sunday.router.reference_at_offset'),
                    routeQuery: $container->get('bear_sunday.semantic.route_query'),
                    resourceReferencesQuery: $container->get('bear_sunday.semantic.resource_references_query'),
                    resourceQuery: $container->get('bear_sunday.semantic.resource_query'),
                    workspaceRoot: $pathResolver->resolve('%project_root%'),
                );
            },
            [ReferenceFinderExtension::TAG_REFERENCE_FINDER => []]
        );

        // リソースクラスの $this->body['...'] のキー補完: JSON Schema の properties から。
        $container->register(
            'bear_sunday.completor.body_property',
            function (Container $container): BodyPropertyCompletor {
                return new BodyPropertyCompletor(
                    schemaQuery: $container->get('bear_sunday.semantic.schema_query'),
                );
            },
            [
                CompletionExtension::TAG_COMPLETOR => [
                    CompletionExtension::KEY_COMPLETOR_TYPES => ['php'],
                ],
            ]
        );

        // v0.3: $this->resource->get('app://self/user') の戻り値を具象リソースクラスとして型付け。
        // プロジェクトルートは %project_root% (LSPではワークスペースルート) を使い、
        // URI → クラス名の導出は ResourceUri / Project に委譲する。
        $container->register(
            'bear_sunday.worse_reflection.resource_client_type_resolver',
            function (Container $container): ResourceClientTypeResolver {
                $pathResolver = $container->get(FilePathResolverExtension::SERVICE_FILE_PATH_RESOLVER);

                return new ResourceClientTypeResolver($pathResolver->resolve('%project_root%'));
            },
            [
                WorseReflectionExtension::TAG_MEMBER_TYPE_RESOLVER => [],
            ]
        );
    }

    public function configure(Resolver $schema): void
    {
    }

    private static function supportsWatchedFileInvalidation(Container $container): bool
    {
        $parameters = $container->getParameters();
        if (($parameters[LanguageServerExtension::PARAM_FILE_EVENTS] ?? false) !== true) {
            return false;
        }
        $globs = $parameters[LanguageServerExtension::PARAM_FILE_EVENT_GLOBS] ?? [];
        if (!is_array($globs) || !in_array('**/*.php', $globs, true)) {
            return false;
        }
        if (!$container->has(ClientCapabilities::class)) {
            return false;
        }

        $capabilities = $container->get(ClientCapabilities::class);
        $workspace = $capabilities->workspace;
        if ($workspace === null || $workspace->didChangeWatchedFiles === null) {
            return false;
        }

        return $workspace->didChangeWatchedFiles->dynamicRegistration === true;
    }
}
