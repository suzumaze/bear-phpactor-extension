<?php

declare(strict_types=1);

namespace Acme\Blog\Domain;

use BEAR\Resource\ResourceObject;

/**
 * リソースの共通基底クラス。実測した実アプリでは src/Domain/ に
 * 中間の基底クラスが置かれていた (PLAN.md §2.17)。リソースのディレクトリの
 * 外にいるため、継承の連鎖は psr-4 対応表で辿らなければ届かない。
 */
abstract class ArticleBase extends ResourceObject
{
}
