<?php

namespace Vendor\App\Resource\Page;

class Index extends \BEAR\Resource\ResourceObject
{
    public function onGet(): static
    {
        $this->body = ['greeting' => 'hello'];

        return $this;
    }
}
