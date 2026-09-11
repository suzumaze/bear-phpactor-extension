<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Resource\Model;

use Suzumaze\BearPhpactor\Semantic\Resource\ResourceQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceResolution;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;

/**
 * URI → リソースクラス (ファイルとFQN) の解決を、定義ジャンプと参照検索で
 * 共有する部品。
 *
 * 参照の同一性は「URI文字列が同じ」ではなく「解決先のファイルが同じ」で判定する
 * (PLAN.md §2.11)。つまりこの解決が1つだけあり、定義側を直せば参照側も同時に
 * 直る。コンテキスト接頭辞や ImportApp (app://tags/ など) の差し替えにも
 * 自動で追随する。
 */
final class ResourceTargetResolver
{
    public function __construct(
        private ResourceQuery $resourceQuery = new ResourceQuery(),
    ) {
    }

    /**
     * Detailed, transport-independent resolution result.
     *
     * @return SemanticResult<ResourceResolution|null>
     */
    public function resolveDetailed(Project $project, ResourceUri $uri): SemanticResult
    {
        return $this->resourceQuery->resolve($project, $uri);
    }

    /**
     * 参照元プロジェクトから見た $uri の解決先。解決できない、またはコンテキスト
     * 接頭辞の候補が2件以上なら null。
     *
     * @return array{file: string, fqn: string}|null
     */
    public function resolve(Project $project, ResourceUri $uri): ?array
    {
        $result = $this->resolveDetailed($project, $uri);
        if ($result->status !== SemanticStatus::Ok || $result->value === null) {
            return null;
        }

        return $result->value->legacyTarget();
    }
}
