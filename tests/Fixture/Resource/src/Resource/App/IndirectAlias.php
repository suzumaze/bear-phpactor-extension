<?php

declare(strict_types=1);

namespace Acme\Blog\Resource\App;

use Acme\Blog\Domain\ArticleBase as ValidArticleBase;

/**
 * 別名インポート経由の間接継承。実測した実アプリで見つかった形
 * (PLAN.md §2.17)。getResolvedName() は別名を解決して完全修飾名を返すため、
 * 短い名前の文字列比較では拾えない。
 */
final class IndirectAlias extends ValidArticleBase
{
}
