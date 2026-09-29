<?php

declare(strict_types=1);

namespace Acme\Shop\Module\Claim;

trait ReviewTrait
{
    public function traitChoice(): void
    {
        if (getenv('FEATURE')) {
            $this->bind('Service')->to('Enabled');
        }
    }
}
