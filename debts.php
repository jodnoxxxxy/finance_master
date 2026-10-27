<?php
declare(strict_types=1);

require_once 'includes/auth_check.php';
require_once 'config/db.php';
require_once 'config/dsa_schema.php';
require_once 'dsa/Graph.php';
require_once 'dsa/Sorting.php';

$userId = (int)$_SESSION['user_id'];
$currency = $_SESSION['user_currency'] ?? '₹';
$flash = '';

ensureDsaSchema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_debt') {
    $debtor = trim((string)($_POST['debtor'] ?? ''));
    $creditor = trim((string)($_POST['creditor'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0);

    if ($debtor !== '' && $creditor !== '' && $debtor !== $creditor && $amount > 0) {
        $stmt = $pdo->prepare("INSERT INTO debts (user_id, debtor, creditor, amount) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, mb_substr($debtor, 0, 100), mb_substr($creditor, 0, 100), $amount]);
        header('Location: debts.php?msg=' . urlencode('IOU added to the graph.'));
        exit();
    }
    $flash = 'Enter two different people and a positive amount.';
}

if (isset($_GET['delete'])) {
    $debtId = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM debts WHERE id = ? AND user_id = ?")->execute([$debtId, $userId]);
    header('Location: debts.php?msg=' . urlencode('IOU removed from the graph.'));
    exit();
}

if (isset($_GET['msg'])) {
    $flash = (string)$_GET['msg'];
}

$stmt = $pdo->prepare("SELECT * FROM debts WHERE user_id = ?");
$stmt->execute([$userId]);
$debtRows = $stmt->fetchAll();
$debtRows = Sorting::sortBy($debtRows, 'id', 'ASC', 'merge');

$graph = new DebtGraph();
foreach ($debtRows as $row) {
    $graph->addDebt((string)$row['debtor'], (string)$row['creditor'], (float)$row['amount']);
}
$settlement = $graph->settle();

$netRows = [];
foreach ($settlement['net_balances'] as $name => $amount) {
    $netRows[] = ['name' => (string)$name, 'amount' => (float)$amount];
}
$netRows = Sorting::sortBy($netRows, 'amount', 'DESC', 'merge');

$transfers = $settlement['transfers'];
$transfers = Sorting::sortBy($transfers, 'amount', 'DESC', 'merge');
?>
<?php include 'includes/header.php'; ?>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="top-navbar">
            <div>
                <h1 style="font-size: 1.8rem; font-weight: 800;">Debt Settlement Simplifier</h1>
                <p style="color: var(--text-secondary); font-size: 0.9rem;">Directed cash-flow graph + greedy debt minimization</p>
            </div>
            <span class="dsa-chip">Graph · two max-heaps · O(V log V)</span>
        </header>

        <?php if ($flash !== ''): ?>
            <div class="dsa-alert"><?= htmlspecialchars($flash) ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="glass-panel stat-card">
                <span class="stat-title">Original IOUs</span>
                <div class="stat-value"><?= (int)$settlement['original_transfer_count'] ?></div>
            </div>
            <div class="glass-panel stat-card">
                <span class="stat-title">Simplified Transfers</span>
                <div class="stat-value" style="color: var(--success);"><?= (int)$settlement['simplified_transfer_count'] ?></div>
            </div>
            <div class="glass-panel stat-card">
                <span class="stat-title">Cash Flow</span>
                <div class="stat-value" style="color: #60a5fa;"><?= $currency . number_format((float)$settlement['simplified_total_flow'], 2) ?></div>
            </div>
            <div class="glass-panel stat-card">
                <span class="stat-title">Transfers Saved</span>
                <div class="stat-value" style="color: var(--warning);"><?= number_format((float)$settlement['reduction_percent'], 1) ?>%</div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1.35fr; gap: 24px;">
            <div>
                <div class="glass-panel" style="margin-bottom: 24px;">
                    <h3 style="margin-bottom: 16px;">Add an IOU</h3>
                    <form method="POST" action="debts.php">
                        <input type="hidden" name="action" value="add_debt">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div class="form-group">
                                <label>Debtor (owes money)</label>
                                <input type="text" name="debtor" class="form-control" placeholder="Aarav" required>
                            </div>
                            <div class="form-group">
                                <label>Creditor (is owed)</label>
                                <input type="text" name="creditor" class="form-control" placeholder="Bhavna" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Amount (<?= $currency ?>)</label>
                            <input type="number" step="0.01" name="amount" class="form-control" placeholder="500.00" required>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%;">Add Directed Edge</button>
                    </form>
                    <p style="color: var(--text-secondary); font-size: 0.72rem; margin-top: 12px;">
                        Try a cycle: Aarav owes Bhavna ₹1,000, Bhavna owes Chirag ₹1,000, Chirag owes Aarav ₹1,000 → nets to zero transfers.
                    </p>
                </div>

                <div class="glass-panel">
                    <h3 style="margin-bottom: 12px;">Original IOUs (Edges)</h3>
                    <?php if (empty($debtRows)): ?>
                        <p style="color: var(--text-secondary); font-size: 0.85rem;">No IOUs yet.</p>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="custom-table" style="margin-top: 0;">
                                <thead><tr><th>Debtor</th><th>Creditor</th><th>Amount</th><th></th></tr></thead>
                                <tbody>
                                    <?php foreach ($debtRows as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['debtor']) ?></td>
                                            <td><?= htmlspecialchars($row['creditor']) ?></td>
                                            <td><?= $currency . number_format((float)$row['amount'], 2) ?></td>
                                            <td><a href="debts.php?delete=<?= (int)$row['id'] ?>" onclick="return confirm('Remove this IOU?')" style="color: var(--danger); text-decoration: none; font-size: 0.85rem;">Remove</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <div class="glass-panel" style="margin-bottom: 24px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <h3 style="font-weight: 700;">Simplified Transfers</h3>
                        <span class="dsa-chip"><?= number_format((float)$settlement['elapsed_ms'], 4) ?> ms · <?= (int)$settlement['node_count'] ?> people</span>
                    </div>
                    <?php if (empty($transfers)): ?>
                        <p style="color: var(--text-secondary); margin-top: 12px; font-size: 0.9rem;">
                            Everyone is settled up — no cash needs to move.
                        </p>
                    <?php else: ?>
                        <table class="custom-table">
                            <thead><tr><th>#</th><th>From (pays)</th><th>To (receives)</th><th>Amount</th></tr></thead>
                            <tbody>
                                <?php foreach ($transfers as $index => $transfer): ?>
                                    <tr>
                                        <td style="color: var(--text-secondary);"><?= $index + 1 ?></td>
                                        <td style="font-weight: 600;"><?= htmlspecialchars($transfer['from']) ?></td>
                                        <td style="font-weight: 600; color: var(--success);"><?= htmlspecialchars($transfer['to']) ?></td>
                                        <td style="font-weight: 700;"><?= $currency . number_format((float)$transfer['amount'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <div class="dsa-metric-list" style="margin-top: 16px;">
                        <div><span>Original total flow</span><strong><?= $currency . number_format((float)$settlement['original_total_flow'], 2) ?></strong></div>
                        <div><span>Simplified total flow</span><strong><?= $currency . number_format((float)$settlement['simplified_total_flow'], 2) ?></strong></div>
                        <div><span>Flow eliminated</span><strong><?= $currency . number_format((float)$settlement['saved_flow'], 2) ?></strong></div>
                    </div>
                </div>

                <div class="glass-panel">
                    <h3 style="font-weight: 700; margin-bottom: 6px;">Net Balances</h3>
                    <p style="color: var(--text-secondary); font-size: 0.78rem; margin-bottom: 10px;">
                        Positive = should receive, negative = should pay. The greedy matcher pairs the largest debtor with the largest creditor using two max-heaps.
                    </p>
                    <?php if (empty($netRows)): ?>
                        <p style="color: var(--text-secondary); font-size: 0.85rem;">No balances to display.</p>
                    <?php else: ?>
                        <table class="custom-table" style="margin-top: 0;">
                            <thead><tr><th>Person</th><th>Net Balance</th><th>Role</th></tr></thead>
                            <tbody>
                                <?php foreach ($netRows as $row): ?>
                                    <tr>
                                        <td style="font-weight: 600;"><?= htmlspecialchars($row['name']) ?></td>
                                        <td style="font-weight: 700; color: <?= $row['amount'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                                            <?= ($row['amount'] >= 0 ? '+' : '-') . $currency . number_format(abs((float)$row['amount']), 2) ?>
                                        </td>
                                        <td>
                                            <?php if (abs((float)$row['amount']) < 0.01): ?>
                                                <span class="badge">SETTLED</span>
                                            <?php elseif ($row['amount'] > 0): ?>
                                                <span class="badge badge-income">CREDITOR</span>
                                            <?php else: ?>
                                                <span class="badge badge-expense">DEBTOR</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>
