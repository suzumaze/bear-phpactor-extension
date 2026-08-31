<?php

declare(strict_types=1);

namespace MyVendor\SqlFixture\Query;

/**
 * 先頭バックスラッシュ付きでも、無関係な名前空間の同名属性ではジャンプしない。
 * 正規化が「なんでも通す」方向に広がっていないことの対照。
 */
interface ForeignNamespaceDbQueryInterface
{
    /**
     * @return array{id: int}
     */
    #[\Foo\Bar\DbQuery('x')]
    public function foreign(): array;
}
