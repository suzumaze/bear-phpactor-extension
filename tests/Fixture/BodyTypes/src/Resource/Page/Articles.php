<?php

declare(strict_types=1);

namespace BodyTypes\Resource\Page;

use BEAR\Resource\ResourceObject;
use BodyTypes\Entity\Article;
use BodyTypes\Repository\ArticleRepository;

final class Articles extends ResourceObject
{
    public function __construct(private ArticleRepository $repository)
    {
    }

    public function onGet(int $id): static
    {
        if ($id === 0) {
            $this->body = ['message' => 'Article not found'];

            return $this;
        }
        $this->body = [
            'article' => $this->repository->find($id),
            'items' => $this->repository->list(),
            'pager' => $this->repository->page(),
            'wrapped' => ['first' => new Article()],
            'title' => 'Articles',
            'unknown' => $this->undefined,
            'total' => 'replaced below',
        ];
        $this->body['total'] = $this->repository->total();

        return $this;
    }

    public function onPost(): static
    {
        foreach ([1, 2] as $id) {
            $this->body[] = $id;
        }

        return $this;
    }
}
