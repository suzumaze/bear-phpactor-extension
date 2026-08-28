<?php

declare(strict_types=1);

namespace Acme\Refs\Fake\Mini\Resource\App;

use BEAR\Resource\ResourceObject;

/**
 * ミニアプリ (tests/Fake/Mini) の同名リソース。src/ 側の Article とは別物で、
 * 同じ 'app://self/article' というURI文字列が、参照元の属するアプリによって
 * 別のクラスを指す (PLAN.md §2.11 の設計判断の存在理由)。
 */
final class Article extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [];

        return $this;
    }
}
