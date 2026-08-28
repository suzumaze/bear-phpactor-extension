<?php

declare(strict_types=1);

namespace Acme\Refs\Fake\Mini\Resource\App;

use BEAR\Resource\ResourceObject;

/**
 * ミニアプリ側から Article を参照する唯一の場所。この文字列は Mini の
 * Article を指す (src/ 側の同名URI文字列は src の Article を指す)。
 */
#[Link(href: 'app://self/article')]
final class Caller extends ResourceObject
{
    public function onGet(): static
    {
        $this->body = [];

        return $this;
    }
}
