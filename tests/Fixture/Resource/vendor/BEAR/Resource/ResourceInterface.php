<?php

declare(strict_types=1);

namespace BEAR\Resource;

/**
 * BEAR.Resource の ResourceInterface のスタブ。
 *
 * このパッケージは BEAR\Resource に依存しないため、フィクスチャ用に
 * 実物のメソッドシグネチャのうち必要な分だけを置く。実物は
 * bear/resource の src/ResourceInterface.php (get/post/put/patch/delete/
 * head/options はすべて string $uri, array $query = [] を受けて
 * ResourceObject を返す)。
 */
interface ResourceInterface
{
    public function get(string $uri, array $query = []): ResourceObject;

    public function post(string $uri, array $query = []): ResourceObject;

    public function put(string $uri, array $query = []): ResourceObject;

    public function patch(string $uri, array $query = []): ResourceObject;

    public function delete(string $uri, array $query = []): ResourceObject;

    public function options(string $uri, array $query = []): ResourceObject;

    public function head(string $uri, array $query = []): ResourceObject;

    public function href(string $rel, array $query = []): ResourceObject;
}
