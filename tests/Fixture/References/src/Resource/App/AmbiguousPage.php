<?php

declare(strict_types=1);

namespace Acme\Refs\Resource\App;

/**
 * 'page://self/x' を書くサイト。Page/Content/X.php と Page/Admin/X.php の
 * どちらにも解決しうるため (コンテキスト接頭辞)、どちらのクラスの参照にも
 * 数えられない (PLAN.md §2.11: 曖昧なサイトは解決しなかったものとして扱う)。
 * このファイル自身はリソースクラスではない (ResourceObject を継承しない)。
 */
final class AmbiguousPage
{
    public function load(): void
    {
        $this->resource->get('page://self/x');
    }
}
