<?php

declare(strict_types=1);

use Acme\Skeleton\Bootstrap;

exit((new Bootstrap())('cli-hal-app', $GLOBALS, $_SERVER));
