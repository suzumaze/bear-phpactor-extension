<?php

declare(strict_types=1);

namespace MyVendor\AlpsFixture\Resource\App;

use BEAR\ApiDoc\Annotation\Alps;

final class AlpsDemo
{
    /**
     * useで取り込んだ短縮名
     */
    #[Alps('doDelete<caret-1>Article')]
    public function onDelete(): void
    {
    }

    /**
     * useなし完全修飾（先頭バックスラッシュ無し）
     */
    #[BEAR\ApiDoc\Annotation\Alps('goA<caret-2>rticle')]
    public function onGet(): void
    {
    }

    /**
     * useなし完全修飾（先頭バックスラッシュ付き）。Ray.Di生成コードの書き方。
     */
    #[\BEAR\ApiDoc\Annotation\Alps('doDelete<caret-3>Article')]
    public function onPost(): void
    {
    }

    /**
     * プロファイルに存在しない記述子ID
     */
    #[Alps('noSuchDescriptor<caret-4>')]
    public function onPut(): void
    {
    }
}
