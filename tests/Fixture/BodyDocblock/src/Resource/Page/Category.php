<?php

declare(strict_types=1);

namespace BodyDocblock\Resource\Page;

use BEAR\Resource\ResourceObject;
use BodyDocblock\Entity\Category as CategoryEntity;

/**
 * An error variant for the guard, a multi-line variant with an optional key for the end of the method.
 *
 * @property array{message: string}|array{
 *     category: CategoryEntity,
 *     articles: list<array{id: int, title: string}>,
 *     next?: string|null,
 * } $body
 */
final class Category extends ResourceObject
{
    public function onGet(int $id): static
    {
        if ($id === 0) {
            $this->body = ['message' => 'Category not found'];

            return $this;
        }
        $this->body = ['category' => new CategoryEntity(), 'articles' => []];

        return $this;
    }
}
