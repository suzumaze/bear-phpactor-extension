<?php

declare(strict_types=1);

namespace Acme\Blog\Resource\App;

/**
 * ResourceObjectを継承しないヘルパークラス。
 * リソースではないためURI補完の候補に出てはならない。
 */
final class NotAResource
{
}
