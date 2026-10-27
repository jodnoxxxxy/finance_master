<?php
declare(strict_types=1);

require_once 'includes/auth_check.php';
require_once 'config/db.php';
require_once 'config/dsa_schema.php';
require_once 'dsa/HashMap.php';
require_once 'dsa/Sorting.php';
require_once 'dsa/Knapsack.php';

$userId = (int)$_SESSION['user_id'];
$currency = $_SESSION['user_currency'] ?? '₹';
$activeTab = ($_GET['tab'] ?? 'budgets') === 'optimizer' ? 'optimizer' : 'budgets';
$flash = '';

ensureDsaSchema($pdo);

// --------------------------------------------------------------- Set a budget
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_budget'])) {
    $categoryId = (int)$_POST['category_id'];
    $monthYear = (string)$_POST['month_year'];
    $amount = (float)$_POST['amount'];

    if ($categoryId > 0 && preg_match('/^\d{4}-\d{2}$/', $monthYear) && $amount > 0) {
        $stmt = $pdo->prepare("SELECT id FROM budgets WHERE user_id = ? AND category_id = ? AND month_year = ?");
        $stmt->execute([$userId, $categoryId, $monthYear]);
        $existing = $stmt->fetch();

        if ($existing) {
            $pdo->prepare("UPDATE budgets SET amount = ? WHERE id = ? AND user_id = ?")
                ->execute([$amount, $existing['id'], $userId]);
        } else {
            $pdo->prepare("INSERT INTO budgets (user_id, category_id, month_year, amount) VALUES (?, ?, ?, ?)")
                ->execute([$userId, $categoryId, $monthYear, $amount]);
        }
        header('Location: budgets.php?msg=' . urlencode('Budget saved.'));
        exit();
    }
}

// ------------------------------------------------------- Wishlist item actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_wishlist') {
    $name = trim((string)($_POST['name'] ?? ''));
    $cost = (float)($_POST['cost'] ?? 0);
    $priority = max(1, min(10, (int)($_POST['priority'] ?? 5)));

    if ($name !== '' && $cost > 0) {
        $stmt = $pdo->prepare("INSERT INTO wishlist_items (user_id, name, cost, priority) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, mb_substr($name, 0, 150), $cost, $priority]);
        header('Location: budgets.php?tab=optimizer&msg=' . urlencode('Wishlist item added.'));
        exit();
    }
    $flash = 'Wishlist item needs a name and a positive cost.';
}

if (isset($_GET['delete_wishlist'])) {
    $itemId = (int)$_GET['delete_wishlist'];
    $pdo->prepare("DELETE FROM wishlist_items WHERE id = ? AND user_id = ?")->execute([$itemId, $userId]);
    header('Location: budgets.php?tab=optimizer&msg=' . urlencode('Wishlist item removed.'));
    exit();
}

// -------------------------------------------------- Knapsack budget cap (DP)
$optimizerBudget = isset($_SESSION['wishlist_budget']) ? (float)$_SESSION['wishlist_budget'] : 15000.0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_optimizer') {
    $candidate = (float)($_POST['budget_cap'] ?? 0);
    if ($candidate > 0) {
        $optimizerBudget = min($candidate, 10000000.0);
        $_SESSION['wishlist_budget'] = $optimizerBudget;
        header('Location: budgets.php?tab=optimizer&msg=' . urlencode('Knapsack solver executed.'));
        exit();
    }
    $flash = 'Enter a budget cap greater than zero.';
}

if (isset($_GET['msg'])) {
    $flash = (string)$_GET['msg'];
}

// ------------------------------------------------------------ Data gathering
$expenseCategories = $pdo->prepare("SELECT * FROM categories WHERE user_id = ? AND type = 'expense'");
$expenseCategories->execute([$userId]);
$expList = $expenseCategories->fetchAll();

$currentMonth = date('Y-m');

// Raw budgets; no ORDER BY and no correlated SUM subquery.
$stmt = $pdo->prepare("
    SELECT b.*, c.name AS category_name
    FROM budgets b
    JOIN categories c ON b.category_id = c.id
    WHERE b.user_id = ?
");
$stmt->execute([$userId]);
$allBudgets = $stmt->fetchAll();
$allBudgets = Sorting::sortBy($allBudgets, 'month_year', 'DESC', 'merge');

// Hash map of category|month -> spent, replacing SQL GROUP BY / SUM.
$spentMap = new HashMap(211);
$txStmt = $pdo->prepare("SELECT category_id, amount, date, type FROM transactions WHERE user_id = ?");
$txStmt->execute([$userId]);
$txCount = 0;
while ($row = $txStmt->fetch()) {
    if ($row['type'] !== 'expense') {
        continue;
    }
    $spentMap->addTo($row['category_id'] . '|' . date('Y-m', strtotime((string)$row['date'])), (float)$row['amount']);
    $txCount++;
}

foreach ($allBudgets as &$budget) {
    $spent = $spentMap->get($budget['category_id'] . '|' . $budget['month_year']);
    $budget['spent'] = is_numeric($spent) ? (float)$spent : 0.0;
}
unset($budget);

// Wishlist + Knapsack
$wishlistStmt = $pdo->prepare("SELECT * FROM wishlist_items WHERE user_id = ?");
$wishlistStmt->execute([$userId]);
$wishlist = $wishlistStmt->fetchAll();
$wishlist = Sorting::sortBy($wishlist, 'priority', 'DESC', 'merge');

$optimization = null;
if (count($wishlist) > 0 && $optimizerBudget > 0) {
    $optimization = Knapsack::solve($wishlist, $optimizerBudget, 'cost', 'priority');
}

$spentStats = $spentMap->stats();
?>
<?php include 'includes/header.php'; ?>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="top-navbar">
            <div>
                <h1 style="font-size: 1.8rem; font-weight: 800;">Monthly Budgets</h1>
                <p style="color: var(--text-secondary); font-size: 0.9rem;">Category limits plus a 0/1 Knapsack wishlist optimiser</p>
            </div>
            <span class="dsa-chip">HashMap · <?= number_format($txCount) ?> rows aggregated · <?= number_format($spentStats['load_factor'] * 100, 1) ?>% load</span>
        </header>

        <?php if ($flash !== ''): ?>
            <div class="dsa-alert"><?= htmlspecialchars($flash) ?></div>
        <?php endif; ?>

        <div class="dsa-tabs">
            <button type="button" class="dsa-tab <?= $activeTab === 'budgets' ? 'active' : '' ?>" onclick="showBudgetTab('budgets')">📊 Category Budgets</button>
            <button type="button" class="dsa-tab <?= $activeTab === 'optimizer' ? 'active' : '' ?>" onclick="showBudgetTab('optimizer')">🎒 Wishlist Optimizer (0/1 Knapsack)</button>
        </div>

        <!-- ------------------------------------------------- Category budgets -->
        <div id="panel-budgets" class="dsa-tab-panel" style="display: <?= $activeTab === 'budgets' ? 'block' : 'none' ?>;">
            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px;">
                <div class="glass-panel" style="height: fit-content;">
                    <h3 style="margin-bottom: 16px;">Set Category Budget</h3>
                    <form method="POST" action="budgets.php">
                        <input type="hidden" name="set_budget" value="1">
                        <div class="form-group">
                            <label>Category</label>
                            <select name="category_id" class="form-control" required>
                                <?php foreach ($expList as $cat): ?>
                                    <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
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
                    <?php if (empty($allBudgets)): ?>
                        <p style="color: var(--text-secondary);">No budgets set yet.</p>
                    <?php endif; ?>
                    <?php foreach ($allBudgets as $b):
                        $percent = ($b['amount'] > 0) ? ($b['spent'] / $b['amount']) * 100 : 0;
                        $class = 'progress-normal';
                        if ($percent >= 80 && $percent <= 100) $class = 'progress-warning';
                        if ($percent > 100) $class = 'progress-danger';
                    ?>
                        <div style="margin-bottom: 20px; padding-bottom: 14px; border-bottom: 1px solid var(--border-card);">
                            <div style="display: flex; justify-content: space-between; font-weight: 600;">
                                <span><?= htmlspecialchars($b['category_name']) ?> (<?= htmlspecialchars($b['month_year']) ?>)</span>
                                <span><?= $currency . number_format($b['spent'], 2) ?> of <?= $currency . number_format((float)$b['amount'], 2) ?></span>
                            </div>
                            <div class="progress-container">
                                <div class="progress-bar-fill <?= $class ?>" style="width: <?= min($percent, 100) ?>%;"></div>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-top: 6px; font-size: 0.8rem;">
                                <span>Usage: <?= round($percent, 1) ?>%</span>
                                <?php if ($percent > 100): ?>
                                    <span style="color: var(--danger); font-weight: 700;">Over budget by <?= $currency . number_format($b['spent'] - (float)$b['amount'], 2) ?>!</span>
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
        </div>

        <!-- ------------------------------------------- Knapsack wishlist panel -->
        <div id="panel-optimizer" class="dsa-tab-panel" style="display: <?= $activeTab === 'optimizer' ? 'block' : 'none' ?>;">
            <div style="display: grid; grid-template-columns: 1fr 2.2fr; gap: 24px;">
                <div>
                    <div class="glass-panel" style="margin-bottom: 24px;">
                        <h3 style="margin-bottom: 16px;">Add Wishlist Item</h3>
                        <form method="POST" action="budgets.php?tab=optimizer">
                            <input type="hidden" name="action" value="add_wishlist">
                            <div class="form-group">
                                <label>Item Name</label>
                                <input type="text" name="name" class="form-control" placeholder="Running shoes" required>
                            </div>
                            <div class="form-group">
                                <label>Cost (<?= $currency ?>)</label>
                                <input type="number" step="0.01" name="cost" class="form-control" placeholder="3000" required>
                            </div>
                            <div class="form-group">
                                <label>Utility / Priority (1–10)</label>
                                <input type="number" min="1" max="10" name="priority" class="form-control" value="5" required>
                            </div>
                            <button type="submit" class="btn btn-primary" style="width: 100%;">Add to Wishlist</button>
                        </form>
                    </div>

                    <div class="glass-panel">
                        <h3 style="margin-bottom: 16px;">Budget Cap</h3>
                        <form method="POST" action="budgets.php?tab=optimizer">
                            <input type="hidden" name="action" value="run_optimizer">
                            <div class="form-group">
                                <label>Maximum spend this month (<?= $currency ?>)</label>
                                <input type="number" step="0.01" name="budget_cap" class="form-control" value="<?= htmlspecialchars(number_format($optimizerBudget, 2, '.', '')) ?>" required>
                            </div>
                            <button type="submit" class="btn btn-success" style="width: 100%;">Run 0/1 Knapsack</button>
                        </form>
                        <p style="color: var(--text-secondary); font-size: 0.72rem; margin-top: 12px;">
                            DP table: O(N × W) time, O(N × W) space. The solver switches to a value-indexed DP when the budget makes an N × W table too large.
                        </p>
                        <p style="color: var(--text-secondary); font-size: 0.72rem; margin-top: 8px;">
                            Recurrence: <code>dp[i][w] = max(dp[i-1][w], priority[i] + dp[i-1][w - cost[i]])</code>
                        </p>
                    </div>
                </div>

                <div class="glass-panel">
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <h3 style="font-weight: 700;">Optimal Purchase Plan</h3>
                        <span class="dsa-chip">0/1 Knapsack · DP</span>
                    </div>

                    <?php if ($optimization === null): ?>
                        <p style="color: var(--text-secondary); margin-top: 14px;">Add at least one wishlist item and set a budget cap to run the optimiser.</p>
                    <?php else: ?>
                        <div class="optimizer-summary">
                            <div><span>Budget</span><strong><?= $currency . number_format($optimization['budget'], 2) ?></strong></div>
                            <div><span>Optimal cost</span><strong><?= $currency . number_format($optimization['total_cost'], 2) ?></strong></div>
                            <div><span>Utility score</span><strong><?= number_format($optimization['total_value'], 2) ?></strong></div>
                            <div><span>Remaining</span><strong><?= $currency . number_format($optimization['remaining'], 2) ?></strong></div>
                            <div><span>Items chosen</span><strong><?= count($optimization['selected']) ?> / <?= (int)$optimization['item_count'] ?></strong></div>
                            <div><span>Solver time</span><strong><?= number_format($optimization['elapsed_ms'], 4) ?> ms</strong></div>
                            <div><span>DP cells</span><strong><?= number_format($optimization['dp_cells']) ?></strong></div>
                            <div><span>Method</span><strong><?= htmlspecialchars(strtoupper($optimization['method'])) ?></strong></div>
                        </div>

                        <?php if ($optimization['method'] === 'greedy'): ?>
                            <p style="color: var(--warning); font-size: 0.8rem; margin-bottom: 10px;">Instance too large for the DP table — a density-greedy heuristic was used.</p>
                        <?php endif; ?>

                        <h4 style="margin: 18px 0 6px; font-size: 0.95rem; color: var(--success);">Buy these</h4>
                        <table class="custom-table" style="margin-top: 0;">
                            <thead><tr><th>Item</th><th>Cost</th><th>Priority</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php if (empty($optimization['selected'])): ?>
                                    <tr><td colspan="4" style="color: var(--text-secondary);">Nothing fits inside this budget.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($optimization['selected'] as $entry): ?>
                                    <tr>
                                        <td style="font-weight: 600;"><?= htmlspecialchars($entry['name']) ?></td>
                                        <td><?= $currency . number_format($entry['cost'], 2) ?></td>
                                        <td><?= number_format($entry['value'], 0) ?>/10</td>
                                        <td><span class="badge badge-income">SELECTED</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if (!empty($optimization['skipped'])): ?>
                            <h4 style="margin: 18px 0 6px; font-size: 0.95rem; color: var(--text-secondary);">Deferred</h4>
                            <table class="custom-table" style="margin-top: 0;">
                                <thead><tr><th>Item</th><th>Cost</th><th>Priority</th><th>Status</th></tr></thead>
                                <tbody>
                                    <?php foreach ($optimization['skipped'] as $entry): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($entry['name']) ?></td>
                                            <td><?= $currency . number_format($entry['cost'], 2) ?></td>
                                            <td><?= number_format($entry['value'], 0) ?>/10</td>
                                            <td><span class="badge badge-expense">DEFERRED</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    <?php endif; ?>

                    <h4 style="margin: 22px 0 6px; font-size: 0.95rem; color: var(--text-secondary);">Wishlist (<?= count($wishlist) ?>)</h4>
                    <div style="overflow-x: auto;">
                        <table class="custom-table" style="margin-top: 0;">
                            <thead><tr><th>Item</th><th>Cost</th><th>Priority</th><th>Action</th></tr></thead>
                            <tbody>
                                <?php if (empty($wishlist)): ?>
                                    <tr><td colspan="4" style="color: var(--text-secondary);">Your wishlist is empty.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($wishlist as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['name']) ?></td>
                                        <td><?= $currency . number_format((float)$item['cost'], 2) ?></td>
                                        <td><?= (int)$item['priority'] ?>/10</td>
                                        <td><a href="budgets.php?tab=optimizer&delete_wishlist=<?= (int)$item['id'] ?>" onclick="return confirm('Remove this item?')" style="color: var(--danger); text-decoration: none; font-size: 0.85rem;">Remove</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
function showBudgetTab(tab) {
    const budgets = document.getElementById('panel-budgets');
    const optimizer = document.getElementById('panel-optimizer');
    const buttons = document.querySelectorAll('.dsa-tab');
    if (!budgets || !optimizer) return;

    if (tab === 'optimizer') {
        budgets.style.display = 'none';
        optimizer.style.display = 'block';
        buttons[0].classList.remove('active');
        buttons[1].classList.add('active');
        history.replaceState(null, '', 'budgets.php?tab=optimizer');
    } else {
        budgets.style.display = 'block';
        optimizer.style.display = 'none';
        buttons[0].classList.add('active');
        buttons[1].classList.remove('active');
        history.replaceState(null, '', 'budgets.php');
    }
}
</script>
</body>
</html>
