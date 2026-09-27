<?php

namespace Acme\Shop\Annotation;

/** Fixture attribute documentation. */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Unused
{
    public function __construct(public string $label = 'fixture-private-default')
    {
    }
}
