<?php

declare(strict_types=1);

namespace BodyTypes\Repository;

use BodyTypes\Entity\Article;
use BodyTypes\Entity\Pager;

final class ArticleRepository
{
    public function find(int $id): ?Article
    {
        return null;
    }

    /** @return list<Article> */
    public function list(): array
    {
        return [];
    }

    public function page(): Pager
    {
        return new Pager();
    }

    public function total(): int
    {
        return 0;
    }
}
