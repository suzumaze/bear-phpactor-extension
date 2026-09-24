<?php

declare(strict_types=1);

namespace TemplateFixture\Resource\App;

use BEAR\Resource\Annotation\Embed as ResourceEmbed;
use BEAR\Resource\ResourceObject;

final class Dashboard extends ResourceObject
{
    #[ResourceEmbed(rel: 'user', src: 'app://self/user')]
    #[ResourceEmbed(rel: 'relativeUser', src: '/user')]
    #[ResourceEmbed(rel: 'missing', src: 'app://self/missing')]
    #[ResourceEmbed(rel: 'escape', src: 'app://self/../../outside')]
    #[ResourceEmbed(rel: 'relativeEscape', src: '/../../outside')]
    #[ResourceEmbed(rel: 'duplicate', src: 'app://self/user')]
    #[ResourceEmbed(rel: 'duplicate', src: 'app://self/missing')]
    public function onGet(): static
    {
        $this->resource->get('app://self/user');

        return $this;
    }
}
