<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Resource\Model;

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
    /**
     * 参照元プロジェクトから見た $uri の解決先。解決できない、またはコンテキスト
     * 接頭辞の候補が2件以上なら null。
     *
     * @return array{file: string, fqn: string}|null
     */
    public function resolve(Project $project, ResourceUri $uri): ?array
    {
        if ($uri->host() === 'self') {
            return $this->resolveSelf($project, $uri);
        }

        // インポートされたホスト (app://tags/ など): ImportApp の対応表から
        // パッケージ内のリソースクラスを解決する。
        return ImportAppRegistry::forProject($project->root())->resolve($uri);
    }

    /**
     * self ホスト: 直接のリソースクラスを優先し、無ければ1階層だけ深い
     * ディレクトリ (コンテキスト接頭辞) の候補を1件だけ返す。
     */
    private function resolveSelf(Project $project, ResourceUri $uri): ?array
    {
        $classFile = $project->classFile($uri);
        $classFqn = $project->classFqn($uri);
        if ($classFile !== null && $classFqn !== null && is_file($classFile)) {
            return ['file' => $classFile, 'fqn' => $classFqn];
        }

        // 直接のクラスが無いときは1階層深いところを探す
        // (アプリがコンテキストで接頭辞を差し込む場合。PLAN.md §2.8 参照)。
        //
        // ただし候補が2件以上のときは何も返さない。定義ジャンプでは phpactor が
        // 候補の選択プロンプト (window/showMessageRequest) をクライアントに送り、
        // 答えないと "Client did not return an action item" のエラー通知が出る
        // (実機のログで確認済み)。1件に決めつけるのも誤った場所へ飛ばすので、
        // 黙って諦める。参照検索も判定を1つに保つ (PLAN.md §2.11)。
        $candidates = $project->classFileCandidates($uri);
        if (count($candidates) !== 1) {
            return null;
        }

        return $candidates[0];
    }
}
