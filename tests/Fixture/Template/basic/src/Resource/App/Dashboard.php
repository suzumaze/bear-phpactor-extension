<?php

declare(strict_types=1);

namespace TemplateFixture\Resource\App;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

final class Dashboard extends ResourceObject
{
    #[Embed(rel: 'user', src: 'app://self/user')]
    #[Embed(rel: 'relativeUser', src: '/user')]
    #[Embed(rel: 'missing', src: 'app://self/missing')]
    #[Embed(rel: 'escape', src: 'app://self/../../outside')]
    #[Embed(rel: 'relativeEscape', src: '/../../outside')]
    #[Embed(rel: 'duplicate', src: 'app://self/user')]
    #[Embed(rel: 'duplicate', src: 'app://self/missing')]
    public function onGet(): static
    {
        return $this;
    }
}
