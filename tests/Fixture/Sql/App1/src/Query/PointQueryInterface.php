<?php

declare(strict_types=1);

namespace MyVendor\SqlFixture\Query;

use Ray\MediaQuery\Annotation\DbQuery;

interface PointQueryInterface
{
    /**
     * @return array{x: int, y: int, squaredDistance: int}
     */
    #[DbQuery('point_distance', type: 'row')]
    public function distance(int $x, int $y): array;
}
