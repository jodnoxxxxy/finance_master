<?php
require_once 'includes/auth_check.php';
require_once 'config/db.php';
require_once 'dsa/Sorting.php';

$userId = $_SESSION['user_id'];
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? 'expense';
    $color = $_POST['color'] ?? '#6366f1';

    if (!empty($name)) {
        $stmt = $pdo->prepare("INSERT INTO categories (user_id, name, type, color) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $name, $type, $color]);
        $msg = "Category successfully added!";
    }
}

if (isset($_GET['delete'])) {
    $catId = (int)$_GET['delete'];
    // Prevent deletion if category is linked to transactions
    $chk = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE category_id = ?");
    $chk->execute([$catId]);
    if ($chk->fetchColumn() > 0) {
        $msg = "Cannot delete: Category has active transactions assigned!";
    } else {
        $pdo->prepare("DELETE FROM categories WHERE id = ? AND user_id = ?")->execute([$catId, $userId]);
        header("Location: categories.php");
        exit();
    }
}

$stmt = $pdo->prepare("SELECT * FROM categories WHERE user_id = ?");
$stmt->execute([$userId]);
$categories = $stmt->fetchAll();
$categories = Sorting::mergeSort($categories, static function (array $a, array $b): int {
    $byType = strcmp((string)$a['type'], (string)$b['type']);
    return $byType !== 0 ? $byType : strcasecmp((string)$a['name'], (string)$b['name']);
});
?>
<?php include 'includes/header.php'; ?>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="top-navbar">
            <h1 style="font-size: 1.8rem; font-weight: 800;">Categories</h1>
        </header>

        <?php if (!empty($msg)): ?>
            <div style="background: rgba(99, 102, 241, 0.2); padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px;">
            <div class="glass-panel" style="height: fit-content;">
                <h3 style="margin-bottom: 16px;">Add New Category</h3>
                <form method="POST" action="categories.php">
                    <input type="hidden" name="add_category" value="1">
                    <div class="form-group">
                        <label>Category Name</label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g., Subscriptions">
                    </div>
                    <div class="form-group">
                        <label>Category Type</label>
                        <select name="type" class="form-control">
                            <option value="expense">Expense</option>
                            <option value="income">Income</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Color Tag</label>
                        <input type="color" name="color" class="form-control" value="#6366f1" style="height: 44px; padding: 4px;">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Create Category</button>
                </form>
            </div>

            <div class="glass-panel">
                <h3 style="margin-bottom: 16px;">Registered Categories</h3>
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Color</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td><div style="width: 18px; height: 18px; border-radius: 50%; background: <?= htmlspecialchars($cat['color']) ?>;"></div></td>
                                <td style="font-weight: 600;"><?= htmlspecialchars($cat['name']) ?></td>
                                <td><span class="badge <?= $cat['type'] === 'income' ? 'badge-income' : 'badge-expense' ?>"><?= strtoupper($cat['type']) ?></span></td>
                                <td>
                                    <a href="categories.php?delete=<?= $cat['id'] ?>" onclick="return confirm('Delete category?')" style="color: var(--danger); text-decoration: none;">Delete</a>
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