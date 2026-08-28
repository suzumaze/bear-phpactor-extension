<?php

declare(strict_types=1);

namespace BEAR\Resource;

/**
 * BEAR.Resource の ResourceObject のスタブ。
 *
 * このパッケージは BEAR\Resource に依存しないため、フィクスチャ用に
 * 最小限の実体を置く。フィクスチャのリソースクラスが使うのは
 * $this->body への代入と extends だけ。
 */
abstract class ResourceObject
{
    /** @var mixed */
    public $body;
}
