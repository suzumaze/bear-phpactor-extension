<?php

declare(strict_types=1);

namespace Acme\Refs\Resource\App;

use BEAR\Resource\ResourceObject;

/**
 * 参照検索の対象。#[Link] も #[Embed] も持たない素のリソースで、
 * 他のリソース (Articles.php / Page/Article.php / Page/Admin/Article.php) から
 * URI文字列で参照される側。
 */
final class Article extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [];

        return $this;
    }
}
