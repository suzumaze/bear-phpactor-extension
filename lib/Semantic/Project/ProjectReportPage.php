<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Project;

/**
 * A count limit and an approximate serialized-item budget for project reports.
 *
 * Room is reserved for the response envelope, summary, and fixed provenance.
 * One oversized item is always returned so offset pagination can advance.
 */
final class ProjectReportPage
{
    private const ITEM_BYTES = 56 * 1024;

    /**
     * @template T
     * @param list<T> $records
     * @param callable(T):int $weight
     * @return list<T>
     */
    public static function slice(array $records, int $offset, int $limit, callable $weight): array
    {
        $page = [];
        $bytes = 0;
        $end = min(count($records), $offset + $limit);
        for ($index = $offset; $index < $end; ++$index) {
            $record = $records[$index];
            $size = $weight($record);
            if ($page !== [] && $bytes + $size > self::ITEM_BYTES) {
                break;
            }
            $page[] = $record;
            $bytes += $size;
        }

        return $page;
    }

    public static function serializedBytes(mixed $value): int
    {
        $json = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? self::ITEM_BYTES : strlen($json) + 16;
    }
}
