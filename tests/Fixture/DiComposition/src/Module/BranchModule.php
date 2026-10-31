<?php

declare(strict_types=1);

namespace Acme\Shop\Module;

use Acme\Shop\Resource\App\Advice;
use Acme\Shop\Resource\App\Replacement;
use Acme\Shop\Service\ClockInterface;
use Ray\Di\AbstractModule;

final class BranchModule extends AbstractModule
{
    protected function configure(): void
    {
        // AppModule binds SystemClock first; this context replaces it with one of two clocks.
        $this->bind(ClockInterface::class)->to(
            getenv('ACME_CLOCK') === 'frozen' ? 'Acme\Shop\Service\FrozenClock' : 'Acme\Shop\Service\NetworkClock',
        );
        // Either Resource class may serve app://self/advice, so neither one's interceptors are reported.
        $this->bind(Advice::class)->to(getenv('ACME_ADVICE') ? Replacement::class : Advice::class);
    }
}
