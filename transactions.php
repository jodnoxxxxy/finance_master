<?php
declare(strict_types=1);

require_once 'includes/auth_check.php';
require_once 'config/db.php';
require_once 'dsa/Stack.php';
require_once 'dsa/Sorting.php';
require_once 'dsa/BST.php';
require_once 'dsa/Trie.php';
require_once 'includes/undo_engine.php';

$userId = (int)$_SESSION['user_id'];
$currency = $_SESSION['user_currency'] ?? '₹';
$undoEngine = TransactionUndoEngine::fromSession($pdo, $userId);

// ---------------------------------------------------------------- Undo / Redo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'undo') {
    $result = $undoEngine->undo();
    header('Location: transactions.php?msg=' . ($result['ok'] ? 'undone' : 'error') . '&text=' . urlencode($result['message']));
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'redo') {
    $result = $undoEngine->redo();
    header('Location: transactions.php?msg=' . ($result['ok'] ? 'redone' : 'error') . '&text=' . urlencode($result['message']));
    exit();
}

// ------------------------------------------------------------ Add transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_transaction') {
    $type = ($_POST['type'] ?? 'expense') === 'income' ? 'income' : 'expense';
    $accountId = (int)($_POST['account_id'] ?? 0);
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $date = (string)($_POST['date'] ?? '');
    $desc = trim((string)($_POST['description'] ?? ''));

    if ($amount > 0 && $date !== '' && $accountId > 0 && $categoryId > 0) {
        // Ownership checks (WHERE is used for access control, not filtering logic).
        $accCheck = $pdo->prepare("SELECT id, name FROM accounts WHERE id = ? AND user_id = ?");
        $accCheck->execute([$accountId, $userId]);
        $account = $accCheck->fetch();

        $catCheck = $pdo->prepare("SELECT id, name FROM categories WHERE id = ? AND user_id = ?");
        $catCheck->execute([$categoryId, $userId]);
        $category = $catCheck->fetch();

        if ($account && $category) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, type, amount, date, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$userId, $accountId, $categoryId, $type, $amount, $date, $desc]);
                $newId = (int)$pdo->lastInsertId();

                $delta = $type === 'income' ? $amount : -$amount;
                $upd = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ? AND user_id = ?");
                $upd->execute([$delta, $accountId, $userId]);

                $fetch = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ?");
                $fetch->execute([$newId, $userId]);
                $newTxn = $fetch->fetch();

                $pdo->commit();

                if ($newTxn) {
                    $label = strtoupper($type) . ' ' . $currency . number_format($amount, 2)
                        . ' · ' . $category['name']
                        . ($desc !== '' ? ' · ' . $desc : '');
                    $undoEngine->recordAdd($newTxn, $label);
                }

                header('Location: transactions.php?msg=added');
                exit();
            } catch (\Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $err = 'Failed to record transaction: ' . $e->getMessage();
            }
        } else {
            $err = 'Invalid account or category selected.';
        }
    } else {
        $err = 'Please fill amount, date, account and category.';
    }
}

// --------------------------------------------------------- Delete transaction
if (isset($_GET['delete'])) {
    $txnId = (int)$_GET['delete'];
    $stmt = $pdo->prepare("
        SELECT t.*, c.name AS category_name
        FROM transactions t
        JOIN categories c ON t.category_id = c.id
        WHERE t.id = ? AND t.user_id = ?
    ");
    $stmt->execute([$txnId, $userId]);
    $t = $stmt->fetch();

    if ($t) {
        $pdo->beginTransaction();
        try {
            $delta = $t['type'] === 'income' ? -(float)$t['amount'] : (float)$t['amount'];
            $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ? AND user_id = ?")
                ->execute([$delta, $t['account_id'], $userId]);
            $pdo->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?")
                ->execute([$txnId, $userId]);
            $pdo->commit();

            $label = 'DELETE ' . strtoupper($t['type']) . ' ' . $currency . number_format((float)$t['amount'], 2)
                . ' · ' . $t['category_name']
                . (!empty($t['description']) ? ' · ' . $t['description'] : '');
            $undoEngine->recordDelete($t, $label);

            header('Location: transactions.php?msg=deleted');
            exit();
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $err = 'Delete failed: ' . $e->getMessage();
        }
    }
}

// ------------------------------------------------------------------- Lookups
$accounts = $pdo->prepare("SELECT * FROM accounts WHERE user_id = ?");
$accounts->execute([$userId]);
$accountsList = $accounts->fetchAll();

$categories = $pdo->prepare("SELECT * FROM categories WHERE user_id = ?");
$categories->execute([$userId]);
$categoryList = $categories->fetchAll();

// Replace SQL ORDER BY type, name with a hand-written comparison sort.
$categoryList = Sorting::mergeSort($categoryList, static function (array $a, array $b): int {
    $byType = strcmp((string)$a['type'], (string)$b['type']);
    return $byType !== 0 ? $byType : strcasecmp((string)$a['name'], (string)$b['name']);
});

// Fetch the raw ledger. No ORDER BY, no LIKE, no aggregate functions.
$transactions = $pdo->prepare("
    SELECT t.*, c.name AS category_name, c.color AS category_color, a.name AS account_name
    FROM transactions t
    JOIN categories c ON t.category_id = c.id
    JOIN accounts a ON t.account_id = a.id
    WHERE t.user_id = ?
");
$transactions->execute([$userId]);
$allTxns = $transactions->fetchAll();

// Build the AVL tree once, keyed by UNIX timestamp.
$treeStart = hrtime(true);
$dateTree = new BST();
foreach ($allTxns as $txn) {
    $timestamp = strtotime($txn['date'] . ' 12:00:00');
    $dateTree->insert($timestamp === false ? 0 : $timestamp, $txn);
}
$treeBuildMs = (hrtime(true) - $treeStart) / 1_000_000;

// Build a Trie of descriptions + merchant tokens (used for the stats panel and
// mirrored by api/suggest.php for live autocomplete).
$trieStart = hrtime(true);
$descriptionTrie = new Trie();
foreach ($allTxns as $txn) {
    if (!empty($txn['description'])) {
        $descriptionTrie->insertText((string)$txn['description']);
    }
}
$trieBuildMs = (hrtime(true) - $trieStart) / 1_000_000;

// --------------------------------------------------- Date range via BST query
$fromDate = trim((string)($_GET['from'] ?? ''));
$toDate = trim((string)($_GET['to'] ?? ''));
$validDate = static function (string $value): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
};

$filteredTxns = $allTxns;
$rangeUsed = false;
$fromTs = null;
$toTs = null;

if (($fromDate !== '' && $validDate($fromDate)) || ($toDate !== '' && $validDate($toDate))) {
    $minKey = $dateTree->minKey();
    $maxKey = $dateTree->maxKey();
    if ($minKey !== null && $maxKey !== null) {
        $fromTs = ($fromDate !== '' && $validDate($fromDate))
            ? strtotime($fromDate . ' 00:00:00')
            : $minKey;
        $toTs = ($toDate !== '' && $validDate($toDate))
            ? strtotime($toDate . ' 23:59:59')
            : $maxKey;
        if ($fromTs !== false && $toTs !== false) {
            $filteredTxns = $dateTree->rangeQuery((int)$fromTs, (int)$toTs);
            $rangeUsed = true;
        }
    }
}

// --------------------------------------------------- Multi-criteria sorting
$sortableFields = ['date', 'amount', 'category', 'description', 'type'];
$requestedSort = (string)($_GET['sort'] ?? 'date');
$sortField = in_array($requestedSort, $sortableFields, true) ? $requestedSort : 'date';
$sortDir = strtoupper((string)($_GET['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
$requestedAlgorithm = (string)($_GET['algo'] ?? 'merge');
$algorithm = in_array($requestedAlgorithm, Sorting::algorithms(), true) ? $requestedAlgorithm : 'merge';

$sortStart = hrtime(true);
$ledger = Sorting::sortBy($filteredTxns, $sortField, $sortDir, $algorithm);
$sortMs = (hrtime(true) - $sortStart) / 1_000_000;

// Flash message
$flashMessage = '';
$flashTone = 'info';
if (isset($_GET['msg'])) {
    $flashTone = in_array($_GET['msg'], ['error'], true) ? 'error' : 'success';
    $flashMessage = match ($_GET['msg']) {
        'added' => 'Transaction saved. Use Undo to reverse it if needed.',
        'deleted' => 'Transaction deleted. The action can be undone.',
        'undone' => (string)($_GET['text'] ?? 'Last action undone.'),
        'redone' => (string)($_GET['text'] ?? 'Action re-applied.'),
        'error' => (string)($_GET['text'] ?? 'That action could not be completed.'),
        default => '',
    };
}
if (!empty($err)) {
    $flashMessage = $err;
    $flashTone = 'error';
}
?>
<?php include 'includes/header.php'; ?>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <h1 style="font-size: 1.8rem; font-weight: 800;">Transactions</h1>
                <p style="color: var(--text-secondary); font-size: 0.9rem;">Record, filter, and maintain income &amp; expenses</p>
            </div>
            <?php include 'includes/undo_controls.php'; ?>
        </header>

        <?php if ($flashMessage !== ''): ?>
            <div class="dsa-alert <?= $flashTone === 'error' ? 'dsa-alert-error' : '' ?>">
                <?= htmlspecialchars($flashMessage) ?>
            </div>
        <?php endif; ?>

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
                                <option value="<?= (int)$acc['id'] ?>"><?= htmlspecialchars($acc['name']) ?> (<?= $currency . number_format((float)$acc['balance'], 2) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category_id" class="form-control" required>
                            <?php foreach ($categoryList as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>">[<?= strtoupper($cat['type']) ?>] <?= htmlspecialchars($cat['name']) ?></option>
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
                        <label>Description / Merchant</label>
                        <div class="autocomplete-wrap">
                            <input type="text" name="description" id="descriptionInput" class="form-control" placeholder="Swiggy, BigBasket, Rent..." autocomplete="off">
                            <div class="autocomplete-list" id="descriptionSuggestions"></div>
                        </div>
                        <small style="color: var(--text-secondary); font-size: 0.72rem;">
                            Prefix suggestions are served by the custom Trie
                            (<?= number_format($descriptionTrie->countWords()) ?> words / <?= number_format($descriptionTrie->countNodes()) ?> nodes).
                        </small>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Save Transaction</button>
                </form>
            </div>

            <!-- Transaction Ledger -->
            <div class="glass-panel">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;">
                    <div>
                        <h3 style="margin-bottom: 6px; font-weight: 700;">Ledger History</h3>
                        <p style="color: var(--text-secondary); font-size: 0.78rem;">
                            <?= count($ledger) ?> of <?= count($allTxns) ?> rows ·
                            sorted by <strong><?= htmlspecialchars($sortField) ?> <?= $sortDir ?></strong>
                            with <strong><?= ucfirst($algorithm) ?>Sort</strong> in <?= number_format($sortMs, 3) ?> ms
                        </p>
                    </div>
                    <div class="dsa-chip-row">
                        <span class="dsa-chip">BST height <?= $dateTree->height() ?></span>
                        <span class="dsa-chip">Trie <?= number_format($descriptionTrie->countNodes()) ?> nodes</span>
                        <span class="dsa-chip"><?= $rangeUsed ? 'BST range active' : 'Full scan (no range)' ?></span>
                    </div>
                </div>

                <form method="GET" action="transactions.php" class="filter-bar">
                    <div>
                        <label>From (BST)</label>
                        <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div>
                        <label>To (BST)</label>
                        <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <div>
                        <label>Sort by</label>
                        <select name="sort" class="form-control">
                            <?php foreach ($sortableFields as $field): ?>
                                <option value="<?= $field ?>" <?= $sortField === $field ? 'selected' : '' ?>><?= ucfirst($field) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Direction</label>
                        <select name="dir" class="form-control">
                            <option value="DESC" <?= $sortDir === 'DESC' ? 'selected' : '' ?>>Descending</option>
                            <option value="ASC" <?= $sortDir === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                        </select>
                    </div>
                    <div>
                        <label>Algorithm</label>
                        <select name="algo" class="form-control">
                            <option value="merge" <?= $algorithm === 'merge' ? 'selected' : '' ?>>MergeSort</option>
                            <option value="quick" <?= $algorithm === 'quick' ? 'selected' : '' ?>>QuickSort</option>
                            <option value="bubble" <?= $algorithm === 'bubble' ? 'selected' : '' ?>>BubbleSort</option>
                            <option value="insertion" <?= $algorithm === 'insertion' ? 'selected' : '' ?>>InsertionSort</option>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">Apply</button>
                        <a href="transactions.php" class="btn btn-ghost">Reset</a>
                    </div>
                </form>

                <div style="overflow-x: auto;">
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
                            <?php if (empty($ledger)): ?>
                                <tr><td colspan="6" style="text-align: center; color: var(--text-secondary);">No records found for this range.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($ledger as $txn): ?>
                                <tr>
                                    <td><?= date('d M, Y', strtotime($txn['date'])) ?></td>
                                    <td><?= htmlspecialchars($txn['description'] ?: 'Unspecified') ?></td>
                                    <td><span class="badge <?= $txn['type'] === 'income' ? 'badge-income' : 'badge-expense' ?>"><?= htmlspecialchars($txn['category_name']) ?></span></td>
                                    <td><?= htmlspecialchars($txn['account_name']) ?></td>
                                    <td style="font-weight: 700; color: <?= $txn['type'] === 'income' ? 'var(--success)' : 'var(--danger)' ?>;">
                                        <?= $txn['type'] === 'income' ? '+' : '-' ?><?= $currency ?><?= number_format((float)$txn['amount'], 2) ?>
                                    </td>
                                    <td>
                                        <a href="transactions.php?delete=<?= (int)$txn['id'] ?>" onclick="return confirm('Delete this transaction? You can undo it afterwards.')" style="color: var(--danger); text-decoration: none; font-size: 0.85rem;">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
(function () {
    const input = document.getElementById('descriptionInput');
    const box = document.getElementById('descriptionSuggestions');
    if (!input || !box) return;
    let timer = null;

    function hide() {
        box.style.display = 'none';
        box.innerHTML = '';
    }

    input.addEventListener('input', function () {
        const query = input.value.trim();
        clearTimeout(timer);
        if (query.length < 1) {
            hide();
            return;
        }
        timer = setTimeout(function () {
            fetch('api/suggest.php?q=' + encodeURIComponent(query))
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    box.innerHTML = '';
                    if (!data.suggestions || data.suggestions.length === 0) {
                        hide();
                        return;
                    }
                    const meta = document.createElement('div');
                    meta.className = 'autocomplete-meta';
                    meta.textContent = 'Trie lookup · ' + data.lookup_us + ' µs';
                    box.appendChild(meta);
                    data.suggestions.forEach(function (item) {
                        const row = document.createElement('div');
                        row.className = 'autocomplete-item';
                        const word = document.createElement('span');
                        word.textContent = item.word;
                        const count = document.createElement('small');
                        count.textContent = item.frequency + '×';
                        row.appendChild(word);
                        row.appendChild(count);
                        row.addEventListener('click', function () {
                            input.value = item.word;
                            hide();
                        });
                        box.appendChild(row);
                    });
                    box.style.display = 'block';
                })
                .catch(hide);
        }, 140);
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.autocomplete-wrap')) hide();
    });
})();
</script>
</body>
</html>
