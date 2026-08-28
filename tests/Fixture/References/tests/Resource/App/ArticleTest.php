<?php

declare(strict_types=1);

namespace Acme\Refs\Tests\Resource\App;

use BEAR\Resource\ResourceObject;

/**
 * リソースディレクトリの下に置かれているが ResourceObject を継承していない
 * クラス。BEAR.Kata では tests/Resource/App/ と tests/Resource/Page/ に
 * PHPUnit のテストクラスが38個あり (extends AbstractAppTestCase / TestCase)、
 * ファイルの場所だけではリソースクラスと区別がつかない。実装は継承を
 * 構文解析で判定するので、クラス名の上で参照検索しても空が返る
 * (PLAN.md §2.11)。ResourceObject に言及しているのは、入口の事前判定
 * (app:// / page:// / ResourceObject の有無) を通過させて、継承チェック
 * が空を返すことをテストで検証するため。
 */
final class ArticleTest
{
    public function testResource(ResourceObject $resource): void
    {
        $this->assertSame(ResourceObject::class, $resource::class);
    }
}
