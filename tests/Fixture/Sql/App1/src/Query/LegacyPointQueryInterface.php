<?php

declare(strict_types=1);

namespace MyVendor\SqlFixture\Query;

/**
 * Ray.QueryModule legacy style: jump to var/db/sql from @Query annotation.
 */
interface LegacyPointQueryInterface
{
    /**
     * @Query("point_distance")
     * @return array{x: int, y: int, squaredDistance: int}
     */
    public function distance(int $x, int $y): array;
}
