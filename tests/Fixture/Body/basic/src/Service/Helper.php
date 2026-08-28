<?php

declare(strict_types=1);

namespace MyVendor\BodyFixture\Service;

final class Helper
{
    public function build(): array
    {
        $this->body['<caret>'];

        return [];
    }
}
