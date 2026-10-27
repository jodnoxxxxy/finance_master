<?php
declare(strict_types=1);

require_once __DIR__ . '/Sorting.php';

/**
 * Knapsack - 0/1 dynamic-programming budget optimiser.
 *
 * Items are indivisible (you either buy the shoes or you do not), so the
 * fractional greedy approach is invalid. The solver chooses the subset that
 * maximises total utility while keeping cost <= budget.
 *
 * Two DP formulations are provided:
 *   - capacity DP : O(N * W)      (used for normal wishlists)
 *   - value DP    : O(N * sum V)  (fallback when the budget has many rupees)
 */
class Knapsack
{
    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public static function solve(
        array $items,
        float $budget,
        string $costKey = 'cost',
        string $valueKey = 'priority',
        int $maxCells = 1200000
    ): array {
        $start = hrtime(true);
        $clean = [];

        foreach ($items as $index => $item) {
            $cost = max(0.0, (float)($item[$costKey] ?? 0));
            $value = max(0.0, (float)($item[$valueKey] ?? 0));
            if ($cost <= 0.0 || $value <= 0.0) {
                continue;
            }
            $clean[] = [
                'index' => count($clean),
                'position' => $index + 1,
                'cost' => $cost,
                'value' => $value,
                'item' => $item,
            ];
        }

        $n = count($clean);
        if ($n === 0 || $budget <= 0.0) {
            return self::buildResult($clean, [], $budget, 'empty', 1, 0, $start);
        }

        // Scale money to integers. Use paise when the table is small enough,
        // otherwise fall back to whole rupees to keep memory bounded.
        $scale = 100;
        while ($scale > 1 && $n * ((int)floor($budget * $scale) + 1) > $maxCells) {
            $scale = intdiv($scale, 10);
        }
        $capacityUnits = (int)floor($budget * $scale);

        if ($n * ($capacityUnits + 1) <= $maxCells) {
            return self::solveByCapacity($clean, $budget, $scale, $capacityUnits, $start);
        }

        return self::solveByValue($clean, $budget, $maxCells, $start);
    }

    /**
     * @param array<int, array<string, mixed>> $clean
     * @return array<string, mixed>
     */
    private static function solveByCapacity(array $clean, float $budget, int $scale, int $capacityUnits, int $start): array
    {
        $n = count($clean);
        $costUnits = [];
        $values = [];
        foreach ($clean as $entry) {
            // ceil() keeps the solution conservative: it can never overspend.
            $costUnits[] = max(1, (int)ceil($entry['cost'] * $scale));
            $values[] = $entry['value'];
        }

        $dp = [];
        $dp[0] = array_fill(0, $capacityUnits + 1, 0.0);

        for ($i = 1; $i <= $n; $i++) {
            $previous = $dp[$i - 1];
            $current = $previous;
            $cost = $costUnits[$i - 1];
            $value = $values[$i - 1];
            for ($w = $cost; $w <= $capacityUnits; $w++) {
                $candidate = $previous[$w - $cost] + $value;
                if ($candidate > $current[$w] + 1e-9) {
                    $current[$w] = $candidate;
                }
            }
            $dp[$i] = $current;
        }

        $selected = [];
        $w = $capacityUnits;
        for ($i = $n; $i >= 1 && $w > 0; $i--) {
            if (abs($dp[$i][$w] - $dp[$i - 1][$w]) > 1e-9) {
                $selected[] = $i - 1;
                $w -= $costUnits[$i - 1];
            }
        }

        return self::buildResult($clean, $selected, $budget, 'capacity', $scale, $n * ($capacityUnits + 1), $start);
    }

    /**
     * Fallback DP indexed by value instead of budget. Handles budgets such as
     * ₹10,00,000 where an N x W table would be too large.
     *
     * @param array<int, array<string, mixed>> $clean
     * @return array<string, mixed>
     */
    private static function solveByValue(array $clean, float $budget, int $maxCells, int $start): array
    {
        $n = count($clean);
        $valueScale = 1;
        foreach ($clean as $entry) {
            if (abs($entry['value'] - round($entry['value'])) > 1e-9) {
                $valueScale = 100;
                break;
            }
        }

        $values = [];
        $totalValue = 0;
        foreach ($clean as $entry) {
            $scaled = max(1, (int)round($entry['value'] * $valueScale));
            $values[] = $scaled;
            $totalValue += $scaled;
        }

        if ($n * ($totalValue + 1) > $maxCells) {
            return self::solveGreedy($clean, $budget, $start);
        }

        $rows = [];
        $rows[0] = array_fill(0, $totalValue + 1, INF);
        $rows[0][0] = 0.0;

        for ($i = 1; $i <= $n; $i++) {
            $previous = $rows[$i - 1];
            $current = $previous;
            $value = $values[$i - 1];
            $cost = $clean[$i - 1]['cost'];
            for ($v = $value; $v <= $totalValue; $v++) {
                if ($previous[$v - $value] === INF) {
                    continue;
                }
                $candidate = $previous[$v - $value] + $cost;
                if ($candidate < $current[$v] - 1e-9) {
                    $current[$v] = $candidate;
                }
            }
            $rows[$i] = $current;
        }

        $bestValue = 0;
        for ($v = $totalValue; $v >= 0; $v--) {
            if ($rows[$n][$v] <= $budget + 1e-9) {
                $bestValue = $v;
                break;
            }
        }

        $selected = [];
        $v = $bestValue;
        for ($i = $n; $i >= 1 && $v > 0; $i--) {
            if (abs($rows[$i][$v] - $rows[$i - 1][$v]) > 1e-9) {
                $selected[] = $i - 1;
                $v -= $values[$i - 1];
            }
        }

        return self::buildResult($clean, $selected, $budget, 'value', $valueScale, $n * ($totalValue + 1), $start);
    }

    /**
     * Defensive fallback for absurdly large instances: density-greedy is not
     * guaranteed optimal, and the UI labels it as such.
     *
     * @param array<int, array<string, mixed>> $clean
     * @return array<string, mixed>
     */
    private static function solveGreedy(array $clean, float $budget, int $start): array
    {
        $ranked = Sorting::mergeSort($clean, static function (array $a, array $b): int {
            $left = $a['value'] / $a['cost'];
            $right = $b['value'] / $b['cost'];
            return $right <=> $left;
        });

        $selected = [];
        $remaining = $budget;
        foreach ($ranked as $entry) {
            if ($entry['cost'] <= $remaining + 1e-9) {
                $selected[] = $entry['index'];
                $remaining -= $entry['cost'];
            }
        }

        return self::buildResult($clean, $selected, $budget, 'greedy', 1, 0, $start);
    }

    /**
     * @param array<int, array<string, mixed>> $clean
     * @param array<int, int> $selectedIndexes
     * @return array<string, mixed>
     */
    private static function buildResult(
        array $clean,
        array $selectedIndexes,
        float $budget,
        string $method,
        int $scale,
        int $dpCells,
        int $start
    ): array {
        $selectedLookup = array_fill_keys($selectedIndexes, true);
        $selected = [];
        $skipped = [];
        $totalCost = 0.0;
        $totalValue = 0.0;

        foreach ($clean as $index => $entry) {
            $row = [
                'position' => $entry['position'],
                'name' => (string)($entry['item']['name'] ?? ('Item ' . $entry['position'])),
                'cost' => $entry['cost'],
                'value' => $entry['value'],
                'item' => $entry['item'],
            ];
            if (isset($selectedLookup[$index])) {
                $selected[] = $row;
                $totalCost += $entry['cost'];
                $totalValue += $entry['value'];
            } else {
                $skipped[] = $row;
            }
        }

        return [
            'selected' => $selected,
            'skipped' => $skipped,
            'total_cost' => $totalCost,
            'total_value' => $totalValue,
            'budget' => $budget,
            'remaining' => max(0.0, $budget - $totalCost),
            'method' => $method,
            'scale' => $scale,
            'dp_cells' => $dpCells,
            'item_count' => count($clean),
            'elapsed_ms' => (hrtime(true) - $start) / 1_000_000,
        ];
    }
}
