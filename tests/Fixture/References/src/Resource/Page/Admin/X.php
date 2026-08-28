<?php

declare(strict_types=1);

namespace Acme\Refs\Resource\Page\Admin;

use BEAR\Resource\ResourceObject;

/**
 * page://self/x のコンテキスト接頭辞の候補 (2/2)。Page/Content/X.php と
 * どちらにも解決しうるため、'page://self/x' を書いたサイトはどちらの
 * クラスの参照にも数えられない (PLAN.md §2.11: 曖昧なサイトは
 * 解決しなかったものとして扱う)。
 */
final class X extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [];

        return $this;
    }
}
