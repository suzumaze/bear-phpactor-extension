<?php

declare(strict_types=1);

namespace MyVendor\SqlFixture\Query;

use Ray\MediaQuery\Annotation\DbQuery;

#[DbQuery(type: 'x')]
interface NamedTypeOnlyQueryInterface
{
}
