<?php

declare(strict_types=1);

namespace BodyGuards\Resource\App;

use BEAR\Resource\ResourceObject;
use InvalidArgumentException;

/** Control flow that never touches the body is stepped over; anything that may change or leave it is not. */
final class Steps extends ResourceObject
{
    /** @param list<array{id: int, name: string}> $rows */
    public function onGet(array $rows): static
    {
        $list = [];
        foreach ($rows as $row) {
            if ($row['id'] === 0) {
                continue;
            }
            $list[] = ['id' => $row['id'], 'label' => strtoupper($row['name'])];
        }
        $this->body = ['list' => $list];
        try {
            $total = count($list);
        } catch (InvalidArgumentException) {
            $total = 0;
        }
        switch ($total) {
            case 0:
                $note = 'empty';
                break;
            default:
                $note = 'some';
        }
        $this->body['note'] = $note;

        return $this;
    }

    /** @param list<int> $ids */
    public function onPost(array $ids): static
    {
        // A closure's `return` does not leave the method.
        $doubled = [];
        for ($i = 0; $i < count($ids); $i++) {
            $doubled[] = array_map(static function (int $id): int {
                return $id * 2;
            }, [$ids[$i]]);
        }
        $this->body = ['doubled' => $doubled];

        return $this;
    }

    /** @param list<int> $ids */
    public function onPut(array $ids): static
    {
        $this->body = ['first' => null];
        // A `return` inside the loop is another way out with its own body.
        foreach ($ids as $id) {
            if ($id > 0) {
                return $this;
            }
        }

        return $this;
    }

    public function onPatch(int $id): static
    {
        // A `throw` leaves the method part way too.
        if ($id === 0) {
            throw new InvalidArgumentException('id');
        }
        $this->body = ['id' => $id];

        return $this;
    }

    /** @param list<int> $ids */
    public function onDelete(array $ids): static
    {
        // A `$this` method call may write the body.
        while ($ids !== []) {
            $this->forget(array_pop($ids));
        }
        $this->body = ['deleted' => true];

        return $this;
    }

    private function forget(int $id): void
    {
        unset($id);
    }
}
