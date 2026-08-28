<?php

declare(strict_types=1);

namespace Acme\Blog\Resource\App;

/**
 * 循環する継承の相手側。CycleA と互いに継承し合う。
 */
final class CycleB extends CycleA
{
}
