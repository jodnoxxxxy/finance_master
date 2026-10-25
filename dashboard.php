<?php
require_once 'includes/auth_check.php';
require_once 'config/db.php';

$userId = $_SESSION['user_id'];
$currency = $_SESSION['user_currency'] ?? '₹';

// 1. Compute Totals
$stmt = $pdo->prepare("SELECT 
    COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS total_income,
    COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS total_expense
    FROM transactions WHERE user_id = ?");
$stmt->execute([$userId]);
$totals = $stmt->fetch();

$totalIncome = $totals['total_income'];
$totalExpense = $totals['total_expense'];
$netBalance = $totalIncome - $totalExpense;

// 2. Compute Total Account Liquid Balances
$stmt = $pdo->prepare("SELECT SUM(balance) as total_wallet_balance FROM accounts WHERE user_id = ?");
$stmt->execute([$userId]);
$walletSum = $stmt->fetch()['total_wallet_balance'] ?? 0;

// 3. Fetch Recent 5 Transactions
$stmt = $pdo->prepare("
    SELECT t.*, c.name AS category_name, c.color AS category_color, a.name AS account_name
    FROM transactions t
    JOIN categories c ON t.category_id = c.id
    JOIN accounts a ON t.account_id = a.id
    WHERE t.user_id = ?
    ORDER BY t.date DESC, t.id DESC LIMIT 5");
$stmt->execute([$userId]);
$recentTransactions = $stmt->fetchAll();

// 4. Monthly Income vs Expense Data for Chart.js
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(date, '%b %Y') as month,
        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as inc,
        SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as exp
    FROM transactions 
    WHERE user_id = ? 
    GROUP BY DATE_FORMAT(date, '%Y-%m')
    ORDER BY date ASC LIMIT 6");
$stmt->execute([$userId]);
$chartData = $stmt->fetchAll();

$chartLabels = json_encode(array_column($chartData, 'month'));
$chartIncome = json_encode(array_column($chartData, 'inc'));
$chartExpense = json_encode(array_column($chartData, 'exp'));

// 5. Active Budgets for Current Month
$currentMonth = date('Y-m');
$stmt = $pdo->prepare("
    SELECT b.amount as budget_limit, c.name as category_name,
    COALESCE((SELECT SUM(amount) FROM transactions WHERE category_id = b.category_id AND user_id = b.user_id AND DATE_FORMAT(date, '%Y-%m') = b.month_year), 0) as spent
    FROM budgets b
    JOIN categories c ON b.category_id = c.id
    WHERE b.user_id = ? AND b.month_year = ?");
$stmt->execute([$userId, $currentMonth]);
$budgets = $stmt->fetchAll();
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
            <div class="user-pill">
                <div class="user-avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
                <span style="font-weight: 600; font-size: 0.9rem;"><?= htmlspecialchars($userName) ?></span>
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

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 30px;">
            <!-- Interactive Analytics Chart -->
            <div class="glass-panel">
                <h3 style="margin-bottom: 18px; font-weight: 700;">6-Month Income vs Expenses</h3>
                <canvas id="flowChart" style="max-height: 280px;"></canvas>
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
                            <span><?= $currency . number_format($b['spent']) ?> / <?= $currency . number_format($b['budget_limit']) ?></span>
                        </div>
                        <div class="progress-container">
                            <div class="progress-bar-fill <?= $class ?>" style="width: <?= min($percent, 100) ?>%;"></div>
                        </div>
                        <?php if ($percent > 100): ?>
                            <span style="color: var(--danger); font-size: 0.72rem; font-weight: 700;">⚠️ Budget Exceeded by <?= $currency . number_format($b['spent'] - $b['budget_limit'], 2) ?>!</span>
                        <?php elseif ($percent >= 80): ?>
                            <span style="color: var(--warning); font-size: 0.72rem; font-weight: 700;">🔔 80% Threshold Reached</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
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
                            <td><?= date('d M, Y', strtotime($t['date'])) ?></td>
                            <td><?= htmlspecialchars($t['account_name']) ?></td>
                            <td><span style="color: <?= htmlspecialchars($t['category_color']) ?>;">●</span> <?= htmlspecialchars($t['category_name']) ?></td>
                            <td><?= htmlspecialchars($t['description'] ?: 'N/A') ?></td>
                            <td style="font-weight: 700; color: <?= $t['type'] === 'income' ? 'var(--success)' : 'var(--danger)' ?>;">
                                <?= $t['type'] === 'income' ? '+' : '-' ?><?= $currency ?><?= number_format($t['amount'], 2) ?>
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