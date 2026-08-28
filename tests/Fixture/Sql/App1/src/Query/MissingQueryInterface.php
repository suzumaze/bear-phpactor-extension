<?php

declare(strict_types=1);

namespace MyVendor\SqlFixture\Query;

use Ray\MediaQuery\Annotation\DbQuery;

/**
 * Both forms reference a query name with no SQL file on disk.
 * Definition jump must return no candidates.
 */
interface MissingQueryInterface
{
    /**
     * @Query("missing_query")
     */
    #[DbQuery('missing_query', type: 'row')]
    public function distance(int $x, int $y): array;
}
