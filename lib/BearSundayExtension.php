<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor;

use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaConventionTypeLocator;
use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaDefinitionLocator;
use Suzumaze\BearPhpactor\Resource\Completor\BodyPropertyCompletor;
use Suzumaze\BearPhpactor\Resource\Completor\ResourceUriCompletor;
use Suzumaze\BearPhpactor\Resource\LanguageServer\ResourceUriDocumentLinkHandler;
use Suzumaze\BearPhpactor\Resource\ReferenceFinder\ResourceDefinitionLocator;
use Suzumaze\BearPhpactor\Resource\ReferenceFinder\ResourceReferenceFinder;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Suzumaze\BearPhpactor\Resource\WorseReflection\ResourceClientTypeResolver;
use Suzumaze\BearPhpactor\Router\RouterDefinitionLocator;
use Suzumaze\BearPhpactor\Sql\SqlDefinitionLocator;
use Phpactor\Container\Container;
use Phpactor\Container\ContainerBuilder;
use Phpactor\Container\Extension;
use Phpactor\Extension\Completion\CompletionExtension;
use Phpactor\Extension\FilePathResolver\FilePathResolverExtension;
use Phpactor\Extension\LanguageServer\LanguageServerExtension;
use Phpactor\Extension\ReferenceFinder\ReferenceFinderExtension;
use Phpactor\Extension\WorseReflection\WorseReflectionExtension;
use Phpactor\MapResolver\Resolver;

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

        $container->register(
            'bear_sunday.resource.definition_locator',
            function (Container $container): ResourceDefinitionLocator {
                return new ResourceDefinitionLocator($container->get('bear_sunday.resource.string_literal_at_offset'));
            },
            [
                ReferenceFinderExtension::TAG_DEFINITION_LOCATOR => [],
            ]
        );

        $container->register(
            'bear_sunday.resource.uri_completor',
            function (Container $container): ResourceUriCompletor {
                return new ResourceUriCompletor($container->get('bear_sunday.resource.string_literal_at_offset'));
            },
            [
                CompletionExtension::TAG_COMPLETOR => [
                    CompletionExtension::KEY_COMPLETOR_TYPES => ['php'],
                ],
            ]
        );

        // リソースURI全体を1つのリンクにする (textDocument/documentLink)。
        // 定義ジャンプ経由ではクリック範囲を指定できず app/self/user が3つに割れるため。
        // phpactor はこのメソッドを未実装なので、登録しても何も置き換えない。
        $container->register(
            'bear_sunday.resource.document_link_handler',
            function (Container $container): ResourceUriDocumentLinkHandler {
                return new ResourceUriDocumentLinkHandler(
                    $container->get(LanguageServerExtension::SERVICE_SESSION_WORKSPACE),
                    $container->get('bear_sunday.resource.definition_locator'),
                );
            },
            [LanguageServerExtension::TAG_METHOD_HANDLER => []]
        );

        // Aura.Router: aura.route.php のルートパスから Page リソースクラスへの定義ジャンプ。
        $container->register(RouterDefinitionLocator::class, function (Container $container) {
            return new RouterDefinitionLocator();
        }, [ReferenceFinderExtension::TAG_DEFINITION_LOCATOR => []]);

        // SQL定義ジャンプ: #[DbQuery('...')] / @Query("...") → var/db/sql/<名前>.sql
        $container->register(SqlDefinitionLocator::class, function (Container $container): SqlDefinitionLocator {
            return new SqlDefinitionLocator();
        }, [
            ReferenceFinderExtension::TAG_DEFINITION_LOCATOR => [],
        ]);

        // JsonSchema: #[JsonSchema('user.json')] 属性からスキーマファイルへ定義ジャンプ。
        // プロジェクトルートは他の3機能と同じく「ドキュメントの位置から上へ composer.json を辿る」方式
        // (ProjectLocator) で、LSPワークスペースの %project_root% には依存しない。
        $container->register(
            'bear_sunday.reference_finder.json_schema_definition_locator',
            function (Container $container) {
                return new JsonSchemaDefinitionLocator(
                    $container->get('bear_sunday.resource.string_literal_at_offset')
                );
            },
            [ReferenceFinderExtension::TAG_DEFINITION_LOCATOR => []]
        );

        // JsonSchema 規約ジャンプ (クラス宣言名 → var/json_schema/<ケバブ>.json) は
        // 定義ジャンプではなく型定義ジャンプ (textDocument/typeDefinition) に載せる。
        // クラス宣言名の上のF12は VS Code の慣習では「その場に留まる」で、定義ジャンプを
        // 上書きすると Shift の押し間違い (⇧F12 のつもり) が「壊れている」ように見える
        // (PLAN.md §2.6 の②の退避先)。該当しないときは空を返し、組込みの型定義解決
        // (WorseReflectionTypeLocator) まで鎖を続ける。
        $container->register(
            'bear_sunday.reference_finder.json_schema_convention_type_locator',
            function (Container $container) {
                return new JsonSchemaConventionTypeLocator();
            },
            [ReferenceFinderExtension::TAG_TYPE_LOCATOR => []]
        );

        // 参照検索: リソースURI文字列・リソースクラス宣言名から、そのリソースを
        // 参照する箇所 (textDocument/references) を探す。必ず false で終わる
        // ファインダーなので、組込みの IndexedReferenceFinder (通常のPHPクラス参照
        // 検索) まで鎖は続く (ChainReferenceFinder は true で止まる)。
        $container->register(
            'bear_sunday.resource.reference_finder',
            function (Container $container): ResourceReferenceFinder {
                return new ResourceReferenceFinder(
                    $container->get('bear_sunday.resource.string_literal_at_offset'),
                );
            },
            [ReferenceFinderExtension::TAG_REFERENCE_FINDER => []]
        );

        // リソースクラスの $this->body['...'] のキー補完: JSON Schema の properties から。
        $container->register(
            'bear_sunday.completor.body_property',
            function (Container $container): BodyPropertyCompletor {
                return new BodyPropertyCompletor();
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
}
