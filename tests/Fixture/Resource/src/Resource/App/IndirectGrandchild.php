<?php

declare(strict_types=1);

namespace Acme\Blog\Resource\App;

use Acme\Blog\Domain\ArticleChild;

/**
 * 2段の連鎖 (孫)。ArticleChild → ArticleBase → ResourceObject と辿る。
 */
final class IndirectGrandchild extends ArticleChild
{
}
