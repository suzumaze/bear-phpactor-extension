<?php

declare(strict_types=1);

namespace MyVendor\SqlFixture\Query;

/**
 * use なしの完全修飾属性（先頭バックスラッシュ付き）。
 * 実アプリの測定で54地点が不発だった書き方。SQLジャンプはここからも飛ぶ。
 */
interface FullyQualifiedDbQueryInterface
{
    /**
     * @return array{id: int, name: string}
     */
    #[\Ray\MediaQuery\Annotation\DbQuery('findFoo', type: 'row')]
    public function findFoo(int $id): array;
}
