<?php

declare(strict_types=1);

namespace BEAR\AppMeta;

abstract class AbstractAppMeta
{
    public string $name;

    public string $appDir;

    public string $tmpDir;

    public string $logDir;

    public string $buildDir;
}
