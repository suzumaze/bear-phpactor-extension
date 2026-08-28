<?php

declare(strict_types=1);

namespace Acme\Refs\Resource\App;

use Acme\Refs\Domain\ArticleBase as ValidArticleBase;

/**
 * 別名インポート経由の間接継承。クラス宣言名の上で参照検索すると、
 * IndirectCaller.php の #[Link] が返る (PLAN.md §2.17 の欠陥の修正)。
 */
final class IndirectArticle extends ValidArticleBase
{
    public function onGet(): static
    {
        $this->body = [];

        return $this;
    }
}
