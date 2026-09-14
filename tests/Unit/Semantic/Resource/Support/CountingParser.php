<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Resource\Support;

use Microsoft\PhpParser\Node\SourceFileNode;
use Microsoft\PhpParser\Parser;

final class CountingParser extends Parser
{
    public int $parseCount = 0;

    public function parseSourceFile(string $fileContents, ?string $uri = null): SourceFileNode
    {
        ++$this->parseCount;

        return parent::parseSourceFile($fileContents, $uri);
    }
}
