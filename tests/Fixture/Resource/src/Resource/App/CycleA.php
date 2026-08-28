<?php

declare(strict_types=1);

namespace Acme\Blog\Resource\App;

/**
 * 循環する継承 (A extends B かつ B extends A)。編集途中の壊れたコードは
 * エディタの中に日常的に存在する。判定は止まらなければならない。
 */
final class CycleA extends CycleB
{
}
