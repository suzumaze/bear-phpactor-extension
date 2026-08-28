<?php

declare(strict_types=1);

namespace MyVendor\SqlFixture\Query;

use Ray\MediaQuery\Annotation\DbQuery;

interface EscapeQueryInterface
{
    /**
     * クエリ名に '..' が含まれるため、var/db/sql の外へは飛ばない (拒否される)。
     */
    #[DbQuery('../escape')]
    public function escape(): array;
}
