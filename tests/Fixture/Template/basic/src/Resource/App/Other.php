<?php

declare(strict_types=1);

namespace TemplateFixture\Resource\App;

use Acme\Other\Embed;
use BEAR\Resource\ResourceObject;

final class Other extends ResourceObject
{
    #[Embed(rel: 'user', src: 'app://self/user')]
    public function onGet(): static
    {
        return $this;
    }
}
