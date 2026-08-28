<?php

declare(strict_types=1);

namespace Acme\Refs\Fake\Mini\Resource\App\Cache;

use BEAR\Resource\ResourceObject;

/**
 * ミニアプリ (tests/Fake/Mini) の複数セグメント・リソース。ルート側に
 * スネーク平坦化の実物 (var/json_schema/cache_article_preview.json) が
 * あっても、ミニアプリ自身の var/json_schema に無ければ規約ジャンプは
 * 降りる (ルートへフォールスルーしない)。
 */
final class ArticlePreview extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [];

        return $this;
    }
}
