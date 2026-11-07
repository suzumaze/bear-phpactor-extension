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
        $maybe = $id > 5 ? $this->repository->rows() : [];
        $this->body = [
            'article' => $this->repository->find($id),
            'assigned' => $saved = $this->repository->page(),
            'items' => $this->repository->list(),
            'maybe' => $maybe,
            'pager' => $this->repository->page(),
            'rows' => $this->repository->rows(),
            'stream' => $this->repository->stream(),
            'wrapped' => ['first' => new Article()],
            'title' => 'Articles',
            'unknown' => $this->undefined,
            'total' => 'replaced below',
        ];
        $this->body['total'] = $this->repository->total();

        return $this;
    }

    public function onPut(int $id): static
    {
        $titles = [];
        foreach ($this->repository->list() as $article) {
            $titles[] = ['article' => $article, 'id' => $id];
        }
        $label = 'none';
        if ($id > 0) {
            $label = 'some';
        }
        $this->body = ['count' => count($titles), 'id' => $id, 'label' => $label, 'titles' => $titles];

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
