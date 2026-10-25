<?php
require_once 'includes/auth_check.php';
require_once 'config/db.php';

$userId = $_SESSION['user_id'];
$currency = $_SESSION['user_currency'] ?? '₹';

// Handle New Transaction Insertion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_transaction') {
    $type = $_POST['type'];
    $accountId = (int)$_POST['account_id'];
    $categoryId = (int)$_POST['category_id'];
    $amount = (float)$_POST['amount'];
    $date = $_POST['date'];
    $desc = trim($_POST['description'] ?? '');

    if ($amount > 0 && !empty($date)) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, type, amount, date, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $accountId, $categoryId, $type, $amount, $date, $desc]);

            // Adjust Account Balance
            if ($type === 'income') {
                $upd = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ? AND user_id = ?");
            } else {
                $upd = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ? AND user_id = ?");
            }
            $upd->execute([$amount, $accountId, $userId]);

            $pdo->commit();
            header("Location: transactions.php?msg=added");
            exit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            $err = "Failed to record transaction: " . $e->getMessage();
        }
    }
}

// Handle Transaction Deletion
if (isset($_GET['delete'])) {
    $txnId = (int)$_GET['delete'];
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ?");
    $stmt->execute([$txnId, $userId]);
    $t = $stmt->fetch();

    if ($t) {
        $pdo->beginTransaction();
        // Reverse account balance adjustment
        if ($t['type'] === 'income') {
            $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?")->execute([$t['amount'], $t['account_id']]);
        } else {
            $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$t['amount'], $t['account_id']]);
        }
        $pdo->prepare("DELETE FROM transactions WHERE id = ?")->execute([$txnId]);
        $pdo->commit();
        header("Location: transactions.php?msg=deleted");
        exit();
    }
}

// Fetch Accounts & Categories for select dropdowns
$accounts = $pdo->prepare("SELECT * FROM accounts WHERE user_id = ?");
$accounts->execute([$userId]);
$accountsList = $accounts->fetchAll();

$categories = $pdo->prepare("SELECT * FROM categories WHERE user_id = ? ORDER BY type, name");
$categories->execute([$userId]);
$categoryList = $categories->fetchAll();

// Fetch All Filtered Transactions
$transactions = $pdo->prepare("
    SELECT t.*, c.name as category_name, a.name as account_name 
    FROM transactions t
    JOIN categories c ON t.category_id = c.id
    JOIN accounts a ON t.account_id = a.id
    WHERE t.user_id = ? 
    ORDER BY t.date DESC");
$transactions->execute([$userId]);
$allTxns = $transactions->fetchAll();
?>
<?php include 'includes/header.php'; ?>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <h1 style="font-size: 1.8rem; font-weight: 800;">Transactions</h1>
                <p style="color: var(--text-secondary); font-size: 0.9rem;">Record, filter, and maintain income & expenses</p>
            </div>
        </header>

        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px;">
            <!-- Transaction Add Panel -->
            <div class="glass-panel" style="height: fit-content;">
                <h3 style="margin-bottom: 18px; font-weight: 700;">Add Transaction</h3>
                <form method="POST" action="transactions.php">
                    <input type="hidden" name="action" value="add_transaction">
                    <div class="form-group">
                        <label>Type</label>
                        <select name="type" class="form-control" required>
                            <option value="expense">Expense (-)</option>
                            <option value="income">Income (+)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Account / Wallet</label>
                        <select name="account_id" class="form-control" required>
                            <?php foreach ($accountsList as $acc): ?>
                                <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['name']) ?> (<?= $currency . number_format($acc['balance'], 2) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category_id" class="form-control" required>
                            <?php foreach ($categoryList as $cat): ?>
                                <option value="<?= $cat['id'] ?>">[<?= strtoupper($cat['type']) ?>] <?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount</label>
                        <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required>
                    </div>
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Description / Note</label>
                        <input type="text" name="description" class="form-control" placeholder="Grocery, Dinner, Dividend...">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Save Transaction</button>
                </form>
            </div>

            <!-- Transaction Ledger -->
            <div class="glass-panel">
                <h3 style="margin-bottom: 16px; font-weight: 700;">Ledger History</h3>
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Category</th>
                            <th>Account</th>
                            <th>Amount</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allTxns)): ?>
                            <tr><td colspan="6" style="text-align: center; color: var(--text-secondary);">No records found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($allTxns as $txn): ?>
                            <tr>
                                <td><?= date('d M, Y', strtotime($txn['date'])) ?></td>
                                <td><?= htmlspecialchars($txn['description'] ?: 'Unspecified') ?></td>
                                <td><span class="badge <?= $txn['type'] === 'income' ? 'badge-income' : 'badge-expense' ?>"><?= htmlspecialchars($txn['category_name']) ?></span></td>
                                <td><?= htmlspecialchars($txn['account_name']) ?></td>
                                <td style="font-weight: 700; color: <?= $txn['type'] === 'income' ? 'var(--success)' : 'var(--danger)' ?>;">
                                    <?= $txn['type'] === 'income' ? '+' : '-' ?><?= $currency ?><?= number_format($txn['amount'], 2) ?>
                                </td>
                                <td>
                                    <a href="transactions.php?delete=<?= $txn['id'] ?>" onclick="return confirm('Delete this transaction?')" style="color: var(--danger); text-decoration: none; font-size: 0.85rem;">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>