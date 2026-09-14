<?php

declare(strict_types=1);

namespace MyVendor\AlpsFixture;

use BEAR\ApiDoc\Annotation\Alps as Semantic;

interface AlpsReferences
{
    #[Semantic('doDeleteArticle')]
    public function first(): void;

    #[\BEAR\ApiDoc\Annotation\Alps('doDeleteArticle')]
    public function second(): void;

    #[Semantic('goArticle')]
    public function another(): void;

    #[\Other\Alps('doDeleteArticle')]
    public function foreign(): void;
}
