<?php
declare(strict_types=1);

/**
 * Sorting - hand-written comparison sorts.
 *
 * No usort(), asort(), ksort() or SQL ORDER BY is used anywhere in the
 * project's data path. All sorters accept a comparator following the
 * spaceship convention.
 */
class Sorting
{
    /** @return array<int, string> */
    public static function algorithms(): array
    {
        return ['merge', 'quick', 'bubble', 'insertion'];
    }

    /**
     * Sort an array of associative rows by a named field.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public static function sortBy(array $items, string $field, string $direction = 'ASC', string $algorithm = 'merge'): array
    {
        $descending = strtoupper($direction) === 'DESC';
        $compare = static function (array $a, array $b) use ($field, $descending): int {
            $left = self::fieldValue($a, $field);
            $right = self::fieldValue($b, $field);

            if (is_numeric($left) && is_numeric($right)) {
                $result = (float)$left <=> (float)$right;
            } else {
                $result = strcasecmp((string)$left, (string)$right);
            }

            if ($result === 0 && $field !== 'id') {
                // Deterministic, newest-first tie break for equal keys.
                $result = ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
                return $result;
            }

            return $descending ? -$result : $result;
        };

        return self::sortWith($items, $compare, $algorithm);
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, mixed>
     */
    public static function sortWith(array $items, callable $compare, string $algorithm = 'merge'): array
    {
        switch (strtolower($algorithm)) {
            case 'quick':
                return self::quickSort($items, $compare);
            case 'bubble':
                return self::bubbleSort($items, $compare);
            case 'insertion':
                return self::insertionSort($items, $compare);
            case 'merge':
            default:
                return self::mergeSort($items, $compare);
        }
    }

    /**
     * Bottom-up merge sort: O(N log N) time, O(N) auxiliary space.
     *
     * @param array<int, mixed> $items
     * @return array<int, mixed>
     */
    public static function mergeSort(array $items, callable $compare): array
    {
        $n = count($items);
        if ($n < 2) {
            return array_values($items);
        }

        $result = array_values($items);
        $temp = array_fill(0, $n, null);

        for ($width = 1; $width < $n; $width *= 2) {
            for ($low = 0; $low < $n; $low += $width * 2) {
                $mid = min($low + $width, $n);
                $high = min($low + $width * 2, $n);
                $left = $low;
                $right = $mid;
                $write = $low;

                while ($left < $mid && $right < $high) {
                    if ($compare($result[$left], $result[$right]) <= 0) {
                        $temp[$write++] = $result[$left++];
                    } else {
                        $temp[$write++] = $result[$right++];
                    }
                }
                while ($left < $mid) {
                    $temp[$write++] = $result[$left++];
                }
                while ($right < $high) {
                    $temp[$write++] = $result[$right++];
                }
            }
            for ($i = 0; $i < $n; $i++) {
                $result[$i] = $temp[$i];
            }
        }

        return $result;
    }

    /**
     * In-place QuickSort with median-of-three pivot and insertion-sort cutoff.
     * Average O(N log N), worst O(N^2) on adversarial input.
     *
     * @param array<int, mixed> $items
     * @return array<int, mixed>
     */
    public static function quickSort(array $items, callable $compare): array
    {
        $items = array_values($items);
        $n = count($items);
        if ($n > 1) {
            self::quickSortRange($items, 0, $n - 1, $compare);
        }
        return $items;
    }

    /**
     * Optimised bubble sort: O(N^2) worst/average, O(N) best (already sorted).
     *
     * @param array<int, mixed> $items
     * @return array<int, mixed>
     */
    public static function bubbleSort(array $items, callable $compare): array
    {
        $items = array_values($items);
        $n = count($items);
        for ($i = 0; $i < $n - 1; $i++) {
            $swapped = false;
            $limit = $n - 1 - $i;
            for ($j = 0; $j < $limit; $j++) {
                if ($compare($items[$j], $items[$j + 1]) > 0) {
                    [$items[$j], $items[$j + 1]] = [$items[$j + 1], $items[$j]];
                    $swapped = true;
                }
            }
            if (!$swapped) {
                break;
            }
        }
        return $items;
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, mixed>
     */
    public static function insertionSort(array $items, callable $compare): array
    {
        $items = array_values($items);
        $n = count($items);
        for ($i = 1; $i < $n; $i++) {
            $current = $items[$i];
            $j = $i - 1;
            while ($j >= 0 && $compare($items[$j], $current) > 0) {
                $items[$j + 1] = $items[$j];
                $j--;
            }
            $items[$j + 1] = $current;
        }
        return $items;
    }

    /** @param array<int, mixed> $items */
    private static function quickSortRange(array &$items, int $low, int $high, callable $compare): void
    {
        while ($low < $high) {
            if ($high - $low < 16) {
                self::insertionSortRange($items, $low, $high, $compare);
                return;
            }

            $mid = intdiv($low + $high, 2);
            if ($compare($items[$mid], $items[$low]) < 0) {
                self::swap($items, $mid, $low);
            }
            if ($compare($items[$high], $items[$low]) < 0) {
                self::swap($items, $high, $low);
            }
            if ($compare($items[$high], $items[$mid]) < 0) {
                self::swap($items, $high, $mid);
            }

            // Median is now at $mid; park it just before the right sentinel.
            $pivot = $items[$mid];
            self::swap($items, $mid, $high - 1);

            $i = $low;
            $j = $high - 1;
            while (true) {
                while ($compare($items[++$i], $pivot) < 0) {
                }
                while ($compare($items[--$j], $pivot) > 0) {
                }
                if ($i >= $j) {
                    break;
                }
                self::swap($items, $i, $j);
            }
            self::swap($items, $i, $high - 1);

            // Recurse into the smaller side, loop on the larger side.
            if ($i - $low < $high - $i) {
                self::quickSortRange($items, $low, $i - 1, $compare);
                $low = $i + 1;
            } else {
                self::quickSortRange($items, $i + 1, $high, $compare);
                $high = $i - 1;
            }
        }
    }

    /** @param array<int, mixed> $items */
    private static function insertionSortRange(array &$items, int $low, int $high, callable $compare): void
    {
        for ($i = $low + 1; $i <= $high; $i++) {
            $current = $items[$i];
            $j = $i - 1;
            while ($j >= $low && $compare($items[$j], $current) > 0) {
                $items[$j + 1] = $items[$j];
                $j--;
            }
            $items[$j + 1] = $current;
        }
    }

    /** @param array<int, mixed> $items */
    private static function swap(array &$items, int $a, int $b): void
    {
        if ($a === $b) {
            return;
        }
        $temp = $items[$a];
        $items[$a] = $items[$b];
        $items[$b] = $temp;
    }

    /** Map a friendly field name to the comparable value. */
    private static function fieldValue(array $row, string $field)
    {
        switch ($field) {
            case 'amount':
                return (float)($row['amount'] ?? 0);
            case 'date':
            case 'deadline':
                $time = strtotime((string)($row['date'] ?? $row['deadline'] ?? ''));
                return $time === false ? 0 : $time;
            case 'id':
                return (int)($row['id'] ?? 0);
            case 'category':
            case 'category_name':
                return (string)($row['category_name'] ?? $row['category'] ?? '');
            case 'description':
                return (string)($row['description'] ?? '');
            case 'account':
            case 'account_name':
                return (string)($row['account_name'] ?? $row['account'] ?? '');
            case 'type':
                return (string)($row['type'] ?? '');
            case 'month_year':
                return (string)($row['month_year'] ?? '');
            case 'name':
                return (string)($row['name'] ?? '');
            case 'priority':
            case 'value':
                return (float)($row['priority'] ?? $row['value'] ?? 0);
            case 'cost':
                return (float)($row['cost'] ?? 0);
            case 'total':
            case 'total_spent':
                return (float)($row['total_spent'] ?? $row['total'] ?? 0);
            default:
                return $row[$field] ?? '';
        }
    }
}
