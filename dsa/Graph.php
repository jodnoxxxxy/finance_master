<?php
declare(strict_types=1);

require_once __DIR__ . '/MinHeap.php';

/**
 * DebtGraph - directed cash-flow graph plus greedy debt simplification.
 *
 * Vertices are people, directed edges mean "debtor owes creditor". After
 * collapsing all edges into one net balance per person, the greedy algorithm
 * repeatedly matches the largest creditor with the largest debtor using two
 * max-heaps. This produces at most V-1 transfers and runs in O(V log V).
 */
class DebtGraph
{
    /** @var array<int, array{debtor: string, creditor: string, amount: float}> */
    private array $edges = [];

    /** @var array<string, array<string, float>> */
    private array $adjacency = [];

    public function addDebt(string $debtor, string $creditor, float $amount): bool
    {
        $debtor = trim($debtor);
        $creditor = trim($creditor);
        if ($debtor === '' || $creditor === '' || $debtor === $creditor || $amount <= 0) {
            return false;
        }

        $this->edges[] = [
            'debtor' => $debtor,
            'creditor' => $creditor,
            'amount' => round($amount, 2),
        ];
        $this->adjacency[$debtor][$creditor] = ($this->adjacency[$debtor][$creditor] ?? 0.0) + $amount;
        if (!isset($this->adjacency[$creditor])) {
            $this->adjacency[$creditor] = [];
        }
        return true;
    }

    /**
     * Positive balance = should receive money; negative = should pay money.
     *
     * @return array<string, float>
     */
    public function netBalances(): array
    {
        $balances = [];
        foreach ($this->edges as $edge) {
            $debtor = $edge['debtor'];
            $creditor = $edge['creditor'];
            $balances[$debtor] = ($balances[$debtor] ?? 0.0) - $edge['amount'];
            $balances[$creditor] = ($balances[$creditor] ?? 0.0) + $edge['amount'];
        }

        foreach ($balances as $name => $amount) {
            $balances[$name] = round($amount, 2);
        }
        return $balances;
    }

    /**
     * @return array<string, mixed>
     */
    public function settle(): array
    {
        $start = hrtime(true);
        $balances = $this->netBalances();
        $epsilon = 0.01;

        $compareLargest = static function (array $a, array $b): int {
            $byAmount = $b['amount'] <=> $a['amount'];
            return $byAmount !== 0 ? $byAmount : strcmp($a['name'], $b['name']);
        };

        $creditors = new MinHeap($compareLargest);
        $debtors = new MinHeap($compareLargest);

        foreach ($balances as $name => $amount) {
            if ($amount > $epsilon) {
                $creditors->insert(['name' => (string)$name, 'amount' => $amount]);
            } elseif ($amount < -$epsilon) {
                $debtors->insert(['name' => (string)$name, 'amount' => -$amount]);
            }
        }

        $transfers = [];
        $guard = 0;
        $maxIterations = max(1, count($balances) * 4);

        while (!$creditors->isEmpty() && !$debtors->isEmpty() && $guard < $maxIterations) {
            $guard++;
            /** @var array{name: string, amount: float} $credit */
            $credit = $creditors->extract();
            /** @var array{name: string, amount: float} $debt */
            $debt = $debtors->extract();

            $amount = round(min($credit['amount'], $debt['amount']), 2);
            if ($amount <= 0.0) {
                break;
            }

            $transfers[] = [
                'from' => $debt['name'],
                'to' => $credit['name'],
                'amount' => $amount,
            ];

            $credit['amount'] = round($credit['amount'] - $amount, 2);
            $debt['amount'] = round($debt['amount'] - $amount, 2);

            if ($credit['amount'] > $epsilon) {
                $creditors->insert($credit);
            }
            if ($debt['amount'] > $epsilon) {
                $debtors->insert($debt);
            }
        }

        $originalFlow = 0.0;
        foreach ($this->edges as $edge) {
            $originalFlow += $edge['amount'];
        }
        $simplifiedFlow = 0.0;
        foreach ($transfers as $transfer) {
            $simplifiedFlow += $transfer['amount'];
        }

        $originalCount = count($this->edges);
        $simplifiedCount = count($transfers);
        $saved = max(0.0, $originalFlow - $simplifiedFlow);

        return [
            'edges' => $this->edges,
            'adjacency' => $this->adjacency,
            'net_balances' => $balances,
            'transfers' => $transfers,
            'node_count' => count($balances),
            'edge_count' => $originalCount,
            'original_transfer_count' => $originalCount,
            'simplified_transfer_count' => $simplifiedCount,
            'original_total_flow' => round($originalFlow, 2),
            'simplified_total_flow' => round($simplifiedFlow, 2),
            'saved_flow' => round($saved, 2),
            'reduction_percent' => $originalCount > 0 ? (($originalCount - $simplifiedCount) / $originalCount) * 100 : 0.0,
            'elapsed_ms' => (hrtime(true) - $start) / 1_000_000,
        ];
    }
}
