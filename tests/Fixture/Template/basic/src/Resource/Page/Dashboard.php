<?php

declare(strict_types=1);

namespace TemplateFixture\Resource\Page;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

final class Dashboard extends ResourceObject
{
    #[Embed(rel: 'user', src: '/user')]
    public function onGet(): static
    {
        return $this;
    }
}
