<?php

declare(strict_types=1);

namespace Acme\Refs\Resource\App;

use Acme\Refs\Domain\PlainBase;

/**
 * Resource/App/ にあるが、継承の連鎖を辿ってもリソースクラスに
 * 辿り着かないクラス。クラス名の上で参照検索しても空が返る
 * (否定側の対照)。
 */
final class IndirectNotResource extends PlainBase
{
}
