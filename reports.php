<?php
require_once 'includes/auth_check.php';
require_once 'config/db.php';

$userId = $_SESSION['user_id'];
$currency = $_SESSION['user_currency'] ?? '₹';

// CSV Export Engine
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=financial_report_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Date', 'Type', 'Category', 'Account', 'Amount', 'Description']);

    $stmt = $pdo->prepare("
        SELECT t.id, t.date, t.type, c.name as category, a.name as account, t.amount, t.description
        FROM transactions t
        JOIN categories c ON t.category_id = c.id
        JOIN accounts a ON t.account_id = a.id
        WHERE t.user_id = ? ORDER BY t.date DESC");
    $stmt->execute([$userId]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit();
}

// Category Distribution Data for Doughnut Chart
$catStats = $pdo->prepare("
    SELECT c.name, SUM(t.amount) as total_spent, c.color
    FROM transactions t
    JOIN categories c ON t.category_id = c.id
    WHERE t.user_id = ? AND t.type = 'expense'
    GROUP BY c.id");
$catStats->execute([$userId]);
$categoriesBreakdown = $catStats->fetchAll();

$pieLabels = json_encode(array_column($categoriesBreakdown, 'name'));
$pieValues = json_encode(array_column($categoriesBreakdown, 'total_spent'));
$pieColors = json_encode(array_column($categoriesBreakdown, 'color'));
?>
<?php include 'includes/header.php'; ?>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="top-navbar">
            <div>
                <h1 style="font-size: 1.8rem; font-weight: 800;">Financial Reports & Analytics</h1>
                <p style="color: var(--text-secondary); font-size: 0.9rem;">View spending patterns and export official ledger statements</p>
            </div>
            <a href="reports.php?export=csv" class="btn btn-primary">📥 Export Ledger to CSV</a>
        </header>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
            <div class="glass-panel" style="display: flex; flex-direction: column; align-items: center;">
                <h3 style="margin-bottom: 18px; width: 100%;">Expense Breakdown by Category</h3>
                <div style="width: 280px; height: 280px;">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>

            <div class="glass-panel">
                <h3 style="margin-bottom: 18px;">Spending Breakdown Summary</h3>
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Total Spent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categoriesBreakdown as $cb): ?>
                            <tr>
                                <td><span style="color: <?= htmlspecialchars($cb['color']) ?>;">●</span> <?= htmlspecialchars($cb['name']) ?></td>
                                <td style="font-weight: 700;"><?= $currency . number_format($cb['total_spent'], 2) ?></td>
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