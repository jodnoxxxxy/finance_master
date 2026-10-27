<?php
declare(strict_types=1);

require_once 'includes/auth_check.php';
require_once 'config/db.php';
require_once 'dsa/HashMap.php';
require_once 'dsa/Sorting.php';

$userId = (int)$_SESSION['user_id'];
$currency = $_SESSION['user_currency'] ?? '₹';

// CSV Export Engine: raw rows are sorted in PHP with MergeSort.
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=financial_report_' . date('Y-m-d') . '.csv');

    $stmt = $pdo->prepare("
        SELECT t.id, t.date, t.type, c.name AS category, a.name AS account, t.amount, t.description
        FROM transactions t
        JOIN categories c ON t.category_id = c.id
        JOIN accounts a ON t.account_id = a.id
        WHERE t.user_id = ?");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    $rows = Sorting::sortBy($rows, 'date', 'DESC', 'merge');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Date', 'Type', 'Category', 'Account', 'Amount', 'Description']);
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit();
}

$aggregateStart = hrtime(true);

// Fetch raw expense rows; GROUP BY is replaced by a chained hash map.
$catStats = $pdo->prepare("
    SELECT c.id AS category_id, c.name, c.color, t.amount, t.type
    FROM transactions t
    JOIN categories c ON t.category_id = c.id
    WHERE t.user_id = ?");
$catStats->execute([$userId]);

$categoryMap = new HashMap(53);
$expenseRows = 0;
$totalExpense = 0.0;
while ($row = $catStats->fetch()) {
    if ($row['type'] !== 'expense') {
        continue;
    }
    $key = (string)$row['category_id'];
    $existing = $categoryMap->get($key);
    if (!is_array($existing)) {
        $existing = [
            'category_id' => (int)$row['category_id'],
            'name' => (string)$row['name'],
            'color' => (string)$row['color'],
            'total_spent' => 0.0,
        ];
    }
    $existing['total_spent'] += (float)$row['amount'];
    $categoryMap->put($key, $existing);
    $totalExpense += (float)$row['amount'];
    $expenseRows++;
}

$categoriesBreakdown = array_map(static function (array $entry): array {
    return $entry['value'];
}, $categoryMap->entries());

$categoriesBreakdown = Sorting::sortBy($categoriesBreakdown, 'total_spent', 'DESC', 'merge');
$aggregateMs = (hrtime(true) - $aggregateStart) / 1_000_000;

$pieLabels = json_encode(array_column($categoriesBreakdown, 'name'));
$pieValues = json_encode(array_column($categoriesBreakdown, 'total_spent'));
$pieColors = json_encode(array_column($categoriesBreakdown, 'color'));
$mapStats = $categoryMap->stats();
?>
<?php include 'includes/header.php'; ?>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="top-navbar">
            <div>
                <h1 style="font-size: 1.8rem; font-weight: 800;">Financial Reports &amp; Analytics</h1>
                <p style="color: var(--text-secondary); font-size: 0.9rem;">View spending patterns and export official ledger statements</p>
            </div>
            <a href="reports.php?export=csv" class="btn btn-primary">📥 Export Ledger to CSV</a>
        </header>

        <div class="dsa-chip-row" style="margin-bottom: 20px;">
            <span class="dsa-chip">HashMap aggregation · <?= number_format($aggregateMs, 3) ?> ms</span>
            <span class="dsa-chip"><?= number_format($expenseRows) ?> rows → <?= number_format($mapStats['size']) ?> categories</span>
            <span class="dsa-chip">Load factor <?= number_format($mapStats['load_factor'] * 100, 1) ?>%</span>
            <span class="dsa-chip">Collision chains <?= number_format($mapStats['collisions']) ?></span>
            <span class="dsa-chip">MergeSort by total DESC</span>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
            <div class="glass-panel" style="display: flex; flex-direction: column; align-items: center;">
                <h3 style="margin-bottom: 18px; width: 100%;">Expense Breakdown by Category</h3>
                <div style="width: 280px; height: 280px;">
                    <canvas id="categoryChart"></canvas>
                </div>
                <p style="color: var(--text-secondary); font-size: 0.8rem; margin-top: 16px;">
                    Total analysed spending: <strong style="color: var(--danger);"><?= $currency . number_format($totalExpense, 2) ?></strong>
                </p>
            </div>

            <div class="glass-panel">
                <h3 style="margin-bottom: 18px;">Spending Breakdown Summary</h3>
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Total Spent</th>
                            <th>Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($categoriesBreakdown)): ?>
                            <tr><td colspan="3" style="color: var(--text-secondary);">No expense data yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($categoriesBreakdown as $cb): ?>
                            <tr>
                                <td><span style="color: <?= htmlspecialchars($cb['color']) ?>;">●</span> <?= htmlspecialchars($cb['name']) ?></td>
                                <td style="font-weight: 700;"><?= $currency . number_format((float)$cb['total_spent'], 2) ?></td>
                                <td style="color: var(--text-secondary);"><?= $totalExpense > 0 ? number_format(((float)$cb['total_spent'] / $totalExpense) * 100, 1) : '0.0' ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<script>
const pieCtx = document.getElementById('categoryChart').getContext('2d');
new Chart(pieCtx, {
    type: 'doughnut',
    data: {
        labels: <?= $pieLabels ?>,
        datasets: [{
            data: <?= $pieValues ?>,
            backgroundColor: <?= $pieColors ?>,
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom', labels: { color: '#94a3b8' } }
        }
    }
});
</script>
</body>
</html>
