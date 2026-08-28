<?php

declare(strict_types=1);

namespace Acme\Blog\Domain;

/**
 * 2段目の連鎖。ArticleBase を継承し、ResourceObject からは2段離れている。
 */
abstract class ArticleChild extends ArticleBase
{
}
