<?php

declare(strict_types=1);

namespace Acme\Blog\Resource\App;

use Acme\Blog\Domain\PlainBase;

/**
 * Resource/App/ にあるが、辿っても ResourceObject に行き着かないクラス。
 * リソースと判定されてはならない (否定側の対照)。
 */
final class IndirectNotResource extends PlainBase
{
}
