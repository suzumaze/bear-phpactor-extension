<?php

namespace Acme\Shop\Resource\App;

use Acme\Shop\Annotation\First;
use Acme\Shop\Annotation\Second;
use BEAR\Resource\ResourceObject;

class Advice extends ResourceObject
{
    #[Second, First]
    public function onGet(): void
    {
    }
    public function onPost(): void
    {
    }
    #[First]
    public function helper(): void
    {
    }
    protected function hidden(): void
    {
    }
}
