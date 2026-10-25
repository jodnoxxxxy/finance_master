<?php
require_once 'includes/auth_check.php';
require_once 'config/db.php';

$userId = $_SESSION['user_id'];
$currency = $_SESSION['user_currency'] ?? '₹';

// Add New Goal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_goal') {
    $title = trim($_POST['title']);
    $target = (float)$_POST['target_amount'];
    $deadline = $_POST['deadline'];

    if (!empty($title) && $target > 0) {
        $stmt = $pdo->prepare("INSERT INTO savings_goals (user_id, title, target_amount, current_amount, deadline) VALUES (?, ?, ?, 0.00, ?)");
        $stmt->execute([$userId, $title, $target, $deadline]);
        header("Location: goals.php");
        exit();
    }
}

// Deposit Money Toward Goal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'deposit_goal') {
    $goalId = (int)$_POST['goal_id'];
    $amount = (float)$_POST['amount'];

    if ($amount > 0) {
        $stmt = $pdo->prepare("UPDATE savings_goals SET current_amount = current_amount + ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$amount, $goalId, $userId]);
        header("Location: goals.php");
        exit();
    }
}

$stmt = $pdo->prepare("SELECT * FROM savings_goals WHERE user_id = ? ORDER BY deadline ASC");
$stmt->execute([$userId]);
$goals = $stmt->fetchAll();
?>
<?php include 'includes/header.php'; ?>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="top-navbar">
            <h1 style="font-size: 1.8rem; font-weight: 800;">Savings Goals</h1>
        </header>

        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px;">
            <div class="glass-panel" style="height: fit-content;">
                <h3 style="margin-bottom: 16px;">Create Target Goal</h3>
                <form method="POST" action="goals.php">
                    <input type="hidden" name="action" value="add_goal">
                    <div class="form-group">
                        <label>Goal Name</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g., Emergency Fund, New Bike" required>
                    </div>
                    <div class="form-group">
                        <label>Target Amount (<?= $currency ?>)</label>
                        <input type="number" step="0.01" name="target_amount" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Target Deadline</label>
                        <input type="date" name="deadline" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Create Goal</button>
                </form>
            </div>

            <div class="glass-panel">
                <h3 style="margin-bottom: 16px;">Active Progress</h3>
                <?php if (empty($goals)): ?>
                    <p style="color: var(--text-secondary);">No goals set yet. Build your wealth roadmap!</p>
                <?php endif; ?>
                <?php foreach ($goals as $g): 
                    $pct = ($g['target_amount'] > 0) ? ($g['current_amount'] / $g['target_amount']) * 100 : 0;
                ?>
                    <div style="background: rgba(255,255,255,0.03); padding: 18px; border-radius: 12px; margin-bottom: 16px; border: 1px solid var(--border-card);">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <h4 style="font-size: 1.1rem; font-weight: 700;"><?= htmlspecialchars($g['title']) ?></h4>
                            <span style="font-size: 0.85rem; color: var(--text-secondary);">Target Date: <?= date('d M, Y', strtotime($g['deadline'])) ?></span>
                        </div>
                        <div class="progress-container" style="height: 14px;">
                            <div class="progress-bar-fill progress-normal" style="width: <?= min($pct, 100) ?>%;"></div>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 8px; font-size: 0.9rem; font-weight: 600;">
                            <span>Saved: <?= $currency . number_format($g['current_amount'], 2) ?> (<?= round($pct, 1) ?>%)</span>
                            <span>Target: <?= $currency . number_format($g['target_amount'], 2) ?></span>
                        </div>
                        
                        <!-- Add money contribution inline form -->
                        <form method="POST" action="goals.php" style="display: flex; gap: 8px; margin-top: 14px;">
                            <input type="hidden" name="action" value="deposit_goal">
                            <input type="hidden" name="goal_id" value="<?= $g['id'] ?>">
                            <input type="number" step="0.01" name="amount" class="form-control" placeholder="Add Amount (<?= $currency ?>)" style="padding: 6px 12px;" required>
                            <button type="submit" class="btn btn-success" style="padding: 6px 16px; white-space: nowrap;">Deposit</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>
</div>
</body>
</html>