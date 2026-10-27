<?php
declare(strict_types=1);

require_once 'includes/auth_check.php';
require_once 'config/db.php';
require_once 'dsa/HashMap.php';
require_once 'dsa/MinHeap.php';
require_once 'dsa/Sorting.php';

$userId = (int)$_SESSION['user_id'];
$currency = $_SESSION['user_currency'] ?? '₹';

// Fetch the raw ledger once. Every aggregate below is computed in PHP with a
// custom data structure instead of SQL SUM / GROUP BY / ORDER BY.
$stmt = $pdo->prepare("
    SELECT t.*, c.name AS category_name, c.color AS category_color, a.name AS account_name
    FROM transactions t
    JOIN categories c ON t.category_id = c.id
    JOIN accounts a ON t.account_id = a.id
    WHERE t.user_id = ?
");
$stmt->execute([$userId]);
$allTxns = $stmt->fetchAll();

$aggregateStart = hrtime(true);

// 1. Income / expense totals via a chained hash table.
$typeTotals = new HashMap(17);
$categoryTotals = new HashMap(101);
$monthTotals = new HashMap(53);
$currentMonth = date('Y-m');
$currentMonthCategory = new HashMap(53);

foreach ($allTxns as $txn) {
    $amount = (float)$txn['amount'];
    $type = (string)$txn['type'];
    $categoryId = (string)$txn['category_id'];

    $typeTotals->addTo($type, $amount);

    if ($type === 'expense') {
        $categoryTotals->addTo($categoryId, $amount);
        if (str_starts_with((string)$txn['date'], $currentMonth)) {
            $currentMonthCategory->addTo($categoryId, $amount);
        }
    }

    $monthKey = date('Y-m', strtotime((string)$txn['date']));
    $bucket = $monthTotals->get($monthKey);
    if (!is_array($bucket)) {
        $bucket = [
            'key' => $monthKey,
            'label' => date('M Y', strtotime($monthKey . '-01')),
            'income' => 0.0,
            'expense' => 0.0,
        ];
    }
    $bucket[$type] += $amount;
    $monthTotals->put($monthKey, $bucket);
}

$totalIncome = (float)($typeTotals->get('income') ?? 0.0);
$totalExpense = (float)($typeTotals->get('expense') ?? 0.0);
$netBalance = $totalIncome - $totalExpense;
$aggregateMs = (hrtime(true) - $aggregateStart) / 1_000_000;

// 2. Account liquidity: loop instead of SQL SUM(balance).
$accountsStmt = $pdo->prepare("SELECT * FROM accounts WHERE user_id = ?");
$accountsStmt->execute([$userId]);
$accountsList = $accountsStmt->fetchAll();
$walletSum = 0.0;
foreach ($accountsList as $account) {
    $walletSum += (float)$account['balance'];
}

// 3. Recent 5 transactions: MergeSort by date DESC, then slice.
$recentStart = hrtime(true);
$recentTransactions = array_slice(Sorting::sortBy($allTxns, 'date', 'DESC', 'merge'), 0, 5);
$recentSortMs = (hrtime(true) - $recentStart) / 1_000_000;

// 4. Top-5 largest expenses with a bounded Min-Heap of size K (O(N log K)).
$topStart = hrtime(true);
$topExpensesHeap = new TopK(5, static function (array $a, array $b): int {
    return (float)$a['amount'] <=> (float)$b['amount'];
});
foreach ($allTxns as $txn) {
    if ($txn['type'] === 'expense') {
        $topExpensesHeap->offer($txn);
    }
}
$topExpenses = $topExpensesHeap->toSortedArray(true);
$topKMs = (hrtime(true) - $topStart) / 1_000_000;

// 5. Six-month chart series from the month hash map, ordered by MergeSort.
$monthEntries = $monthTotals->entries();
$monthEntries = Sorting::mergeSort($monthEntries, static function (array $a, array $b): int {
    return strcmp((string)$a['key'], (string)$b['key']);
});
$monthEntries = array_slice($monthEntries, -6);
$chartRows = array_map(static function (array $entry): array {
    return $entry['value'];
}, $monthEntries);

$chartLabels = json_encode(array_column($chartRows, 'label'));
$chartIncome = json_encode(array_column($chartRows, 'income'));
$chartExpense = json_encode(array_column($chartRows, 'expense'));

// 6. Budgets: raw budget rows + hash lookup for the spent amount.
$stmt = $pdo->prepare("
    SELECT b.amount AS budget_limit, b.category_id, c.name AS category_name
    FROM budgets b
    JOIN categories c ON b.category_id = c.id
    WHERE b.user_id = ? AND b.month_year = ?
");
$stmt->execute([$userId, $currentMonth]);
$budgets = $stmt->fetchAll();
foreach ($budgets as &$budget) {
    $spent = $currentMonthCategory->get((string)$budget['category_id']);
    $budget['spent'] = is_numeric($spent) ? (float)$spent : 0.0;
}
unset($budget);

$categoryStats = $categoryTotals->stats();
$monthStats = $monthTotals->stats();
?>
<?php include 'includes/header.php'; ?>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <h1 style="font-size: 1.8rem; font-weight: 800;">Financial Overview</h1>
                <p style="color: var(--text-secondary); font-size: 0.9rem;">Welcome back, <?= htmlspecialchars($userName) ?> 👋</p>
            </div>
            <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
                <?php include 'includes/undo_controls.php'; ?>
                <div class="user-pill">
                    <div class="user-avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
                    <span style="font-weight: 600; font-size: 0.9rem;"><?= htmlspecialchars($userName) ?></span>
                </div>
            </div>
        </header>

        <!-- Dynamic Stat Metric Cards -->
        <div class="stats-grid">
            <div class="glass-panel stat-card">
                <span class="stat-title">Net Cash Flow</span>
                <div class="stat-value" style="color: <?= $netBalance >= 0 ? 'var(--text-primary)' : 'var(--danger)' ?>;">
                    <?= $currency ?><?= number_format($netBalance, 2) ?>
                </div>
            </div>
            <div class="glass-panel stat-card">
                <span class="stat-title">Total Income</span>
                <div class="stat-value" style="color: var(--success);">
                    +<?= $currency ?><?= number_format($totalIncome, 2) ?>
                </div>
            </div>
            <div class="glass-panel stat-card">
                <span class="stat-title">Total Spending</span>
                <div class="stat-value" style="color: var(--danger);">
                    -<?= $currency ?><?= number_format($totalExpense, 2) ?>
                </div>
            </div>
            <div class="glass-panel stat-card">
                <span class="stat-title">Accounts Liquidity</span>
                <div class="stat-value" style="color: #60a5fa;">
                    <?= $currency ?><?= number_format($walletSum, 2) ?>
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 24px;">
            <!-- Interactive Analytics Chart -->
            <div class="glass-panel">
                <h3 style="margin-bottom: 18px; font-weight: 700;">6-Month Income vs Expenses</h3>
                <canvas id="flowChart" style="max-height: 280px;"></canvas>
                <p style="color: var(--text-secondary); font-size: 0.72rem; margin-top: 12px;">
                    Buckets built with the custom HashMap, ordered with MergeSort — no SQL GROUP BY / ORDER BY.
                </p>
            </div>

            <!-- Active Budgets with 80% & Exceeded Visual Warnings -->
            <div class="glass-panel">
                <h3 style="margin-bottom: 18px; font-weight: 700;">Monthly Budget Limits</h3>
                <?php if (empty($budgets)): ?>
                    <p style="color: var(--text-secondary); font-size: 0.85rem;">No active budgets set for <?= date('F Y') ?>.</p>
                <?php endif; ?>
                <?php foreach ($budgets as $b):
                    $percent = ($b['budget_limit'] > 0) ? ($b['spent'] / $b['budget_limit']) * 100 : 0;
                    $class = 'progress-normal';
                    if ($percent >= 80 && $percent <= 100) $class = 'progress-warning';
                    if ($percent > 100) $class = 'progress-danger';
                ?>
                    <div style="margin-bottom: 16px;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.85rem; font-weight: 600;">
                            <span><?= htmlspecialchars($b['category_name']) ?></span>
                            <span><?= $currency . number_format($b['spent']) ?> / <?= $currency . number_format((float)$b['budget_limit']) ?></span>
                        </div>
                        <div class="progress-container">
                            <div class="progress-bar-fill <?= $class ?>" style="width: <?= min($percent, 100) ?>%;"></div>
                        </div>
                        <?php if ($percent > 100): ?>
                            <span style="color: var(--danger); font-size: 0.72rem; font-weight: 700;">⚠️ Budget Exceeded by <?= $currency . number_format($b['spent'] - (float)$b['budget_limit'], 2) ?>!</span>
                        <?php elseif ($percent >= 80): ?>
                            <span style="color: var(--warning); font-size: 0.72rem; font-weight: 700;">🔔 80% Threshold Reached</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- DSA showcase: Top-K heap + live hash table statistics -->
        <div style="display: grid; grid-template-columns: 1.4fr 1fr; gap: 24px; margin-bottom: 24px;">
            <div class="glass-panel">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h3 style="font-weight: 700;">Top-5 Largest Expenses</h3>
                    <span class="dsa-chip">Min-Heap · O(N log K) · <?= number_format($topKMs, 3) ?> ms</span>
                </div>
                <?php if (empty($topExpenses)): ?>
                    <p style="color: var(--text-secondary); font-size: 0.85rem;">No expenses recorded yet.</p>
                <?php else: ?>
                    <table class="custom-table" style="margin-top: 4px;">
                        <thead>
                            <tr><th>#</th><th>Description</th><th>Category</th><th>Date</th><th>Amount</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topExpenses as $rank => $expense): ?>
                                <tr>
                                    <td style="color: var(--warning); font-weight: 800;"><?= $rank + 1 ?></td>
                                    <td><?= htmlspecialchars($expense['description'] ?: 'Unspecified') ?></td>
                                    <td><span class="badge badge-expense"><?= htmlspecialchars($expense['category_name']) ?></span></td>
                                    <td><?= date('d M, Y', strtotime((string)$expense['date'])) ?></td>
                                    <td style="font-weight: 700; color: var(--danger);">-<?= $currency ?><?= number_format((float)$expense['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="glass-panel">
                <h3 style="font-weight: 700; margin-bottom: 14px;">DSA Engine Activity</h3>
                <div class="dsa-metric-list">
                    <div><span>Rows streamed</span><strong><?= number_format(count($allTxns)) ?></strong></div>
                    <div><span>Hash aggregation</span><strong><?= number_format($aggregateMs, 3) ?> ms</strong></div>
                    <div><span>Category map size</span><strong><?= number_format($categoryStats['size']) ?></strong></div>
                    <div><span>Hash load factor</span><strong><?= number_format($categoryStats['load_factor'] * 100, 1) ?>%</strong></div>
                    <div><span>Collision chains</span><strong><?= number_format($categoryStats['collisions']) ?></strong></div>
                    <div><span>Max chain length</span><strong><?= number_format($categoryStats['max_chain']) ?></strong></div>
                    <div><span>Recent-5 MergeSort</span><strong><?= number_format($recentSortMs, 3) ?> ms</strong></div>
                    <div><span>Top-K heap size</span><strong><?= $topExpensesHeap->size() ?> / 5</strong></div>
                </div>
                <p style="color: var(--text-secondary); font-size: 0.72rem; margin-top: 14px;">
                    The dashboard issues zero SQL aggregate or sort clauses. Totals, budgets and chart buckets are derived from the hash table; recent rows use MergeSort; the Top-K widget streams expenses through a bounded heap.
                </p>
            </div>
        </div>

        <!-- Recent Transactions Table -->
        <div class="glass-panel">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                <h3 style="font-weight: 700;">Recent Transactions</h3>
                <a href="transactions.php" class="btn btn-primary" style="padding: 6px 14px; font-size: 0.85rem;">View All +</a>
            </div>
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Account</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentTransactions)): ?>
                        <tr><td colspan="5" style="text-align: center; color: var(--text-secondary);">No transactions recorded yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($recentTransactions as $t): ?>
                        <tr>
                            <td><?= date('d M, Y', strtotime((string)$t['date'])) ?></td>
                            <td><?= htmlspecialchars($t['account_name']) ?></td>
                            <td><span style="color: <?= htmlspecialchars($t['category_color']) ?>;">●</span> <?= htmlspecialchars($t['category_name']) ?></td>
                            <td><?= htmlspecialchars($t['description'] ?: 'N/A') ?></td>
                            <td style="font-weight: 700; color: <?= $t['type'] === 'income' ? 'var(--success)' : 'var(--danger)' ?>;">
                                <?= $t['type'] === 'income' ? '+' : '-' ?><?= $currency ?><?= number_format((float)$t['amount'], 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

<script>
const ctx = document.getElementById('flowChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= $chartLabels ?>,
        datasets: [
            {
                label: 'Income',
                data: <?= $chartIncome ?>,
                backgroundColor: 'rgba(16, 185, 129, 0.75)',
                borderRadius: 6
            },
            {
                label: 'Expense',
                data: <?= $chartExpense ?>,
                backgroundColor: 'rgba(239, 68, 68, 0.75)',
                borderRadius: 6
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { labels: { color: '#94a3b8' } } },
        scales: {
            x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(255,255,255,0.05)' } },
            y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(255,255,255,0.05)' } }
        }
    }
});
</script>
</body>
</html>
