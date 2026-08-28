<?php

declare(strict_types=1);

namespace Acme\Refs\Resource\App;

use BEAR\Resource\ResourceObject;

/**
 * 誰からも参照されないリソース。参照検索は空を返す (例外を投げない) ことの
 * 受け入れ基準に使う。
 */
final class Orphan extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [];

        return $this;
    }
}
