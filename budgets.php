<?php
require_once 'includes/auth_check.php';
require_once 'config/db.php';

$userId = $_SESSION['user_id'];
$currency = $_SESSION['user_currency'] ?? '₹';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_budget'])) {
    $categoryId = (int)$_POST['category_id'];
    $monthYear = $_POST['month_year'];
    $amount = (float)$_POST['amount'];

    // Insert or update existing budget for this category and month
    $stmt = $pdo->prepare("SELECT id FROM budgets WHERE user_id = ? AND category_id = ? AND month_year = ?");
    $stmt->execute([$userId, $categoryId, $monthYear]);
    $existing = $stmt->fetch();

    if ($existing) {
        $pdo->prepare("UPDATE budgets SET amount = ? WHERE id = ?")->execute([$amount, $existing['id']]);
    } else {
        $pdo->prepare("INSERT INTO budgets (user_id, category_id, month_year, amount) VALUES (?, ?, ?, ?)")->execute([$userId, $categoryId, $monthYear, $amount]);
    }
    header("Location: budgets.php");
    exit();
}

$expenseCategories = $pdo->prepare("SELECT * FROM categories WHERE user_id = ? AND type = 'expense'");
$expenseCategories->execute([$userId]);
$expList = $expenseCategories->fetchAll();

$currentMonth = date('Y-m');
$stmt = $pdo->prepare("
    SELECT b.*, c.name as category_name,
    COALESCE((SELECT SUM(amount) FROM transactions WHERE category_id = b.category_id AND user_id = b.user_id AND DATE_FORMAT(date, '%Y-%m') = b.month_year), 0) as spent
    FROM budgets b
    JOIN categories c ON b.category_id = c.id
    WHERE b.user_id = ?
    ORDER BY b.month_year DESC");
$stmt->execute([$userId]);
$allBudgets = $stmt->fetchAll();
?>
<?php include 'includes/header.php'; ?>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="top-navbar">
            <h1 style="font-size: 1.8rem; font-weight: 800;">Monthly Budgets</h1>
        </header>

        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px;">
            <div class="glass-panel" style="height: fit-content;">
                <h3 style="margin-bottom: 16px;">Set Category Budget</h3>
                <form method="POST" action="budgets.php">
                    <input type="hidden" name="set_budget" value="1">
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category_id" class="form-control" required>
                            <?php foreach ($expList as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Month (Year-Month)</label>
                        <input type="month" name="month_year" class="form-control" value="<?= date('Y-m') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Budget Limit (<?= $currency ?>)</label>
                        <input type="number" step="0.01" name="amount" class="form-control" placeholder="5000.00" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Set Budget</button>
                </form>
            </div>

            <div class="glass-panel">
                <h3 style="margin-bottom: 16px;">Budget Statuses</h3>
                <?php foreach ($allBudgets as $b): 
                    $percent = ($b['amount'] > 0) ? ($b['spent'] / $b['amount']) * 100 : 0;
                    $class = 'progress-normal';
                    if ($percent >= 80 && $percent <= 100) $class = 'progress-warning';
                    if ($percent > 100) $class = 'progress-danger';
                ?>
                    <div style="margin-bottom: 20px; padding-bottom: 14px; border-bottom: 1px solid var(--border-card);">
                        <div style="display: flex; justify-content: space-between; font-weight: 600;">
                            <span><?= htmlspecialchars($b['category_name']) ?> (<?= $b['month_year'] ?>)</span>
                            <span><?= $currency . number_format($b['spent'], 2) ?> of <?= $currency . number_format($b['amount'], 2) ?></span>
                        </div>
                        <div class="progress-container">
                            <div class="progress-bar-fill <?= $class ?>" style="width: <?= min($percent, 100) ?>%;"></div>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 6px; font-size: 0.8rem;">
                            <span>Usage: <?= round($percent, 1) ?>%</span>
                            <?php if ($percent > 100): ?>
                                <span style="color: var(--danger); font-weight: 700;">Over budget by <?= $currency . number_format($b['spent'] - $b['amount'], 2) ?>!</span>
                            <?php elseif ($percent >= 80): ?>
                                <span style="color: var(--warning); font-weight: 700;">80% Threshold Reached</span>
                            <?php else: ?>
                                <span style="color: var(--success);">Within limits</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>
</div>
</body>
</html>