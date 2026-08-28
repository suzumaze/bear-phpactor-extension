<?php

declare(strict_types=1);

namespace Acme\Blog\Resource\App;

/**
 * ResourceObject ではなく独自の基底クラス (MyResourceObject) を継承するクラス。
 * リソースではないためURI補完の候補に出てはならない。
 */
final class ExtendsCustomBase extends MyResourceObject
{
}
