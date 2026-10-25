<?php
require_once 'includes/auth_check.php';
require_once 'config/db.php';

$userId = $_SESSION['user_id'];
$status = '';

// Update Profile & Preferences
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name']);
    $currency = trim($_POST['currency']);

    $stmt = $pdo->prepare("UPDATE users SET name = ?, currency = ? WHERE id = ?");
    $stmt->execute([$name, $currency, $userId]);
    $_SESSION['user_name'] = $name;
    $_SESSION['user_currency'] = $currency;
    $status = 'Profile updated successfully!';
}

// Change Password Securely
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $oldPass = $_POST['old_password'];
    $newPass = $_POST['new_password'];

    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if ($user && password_verify($oldPass, $user['password'])) {
        $newHash = password_hash($newPass, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $userId]);
        $status = 'Password changed successfully!';
    } else {
        $status = 'Incorrect current password!';
    }
}

$user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$user->execute([$userId]);
$userData = $user->fetch();
?>
<?php include 'includes/header.php'; ?>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="top-navbar">
            <h1 style="font-size: 1.8rem; font-weight: 800;">Account Settings</h1>
        </header>

        <?php if (!empty($status)): ?>
            <div style="background: rgba(99, 102, 241, 0.2); padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                <?= htmlspecialchars($status) ?>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
            <div class="glass-panel">
                <h3 style="margin-bottom: 16px;">Profile & Preferences</h3>
                <form method="POST" action="settings.php">
                    <input type="hidden" name="update_profile" value="1">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($userData['name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Preferred Currency Symbol</label>
                        <select name="currency" class="form-control">
                            <option value="₹" <?= $userData['currency'] === '₹' ? 'selected' : '' ?>>INR (₹)</option>
                            <option value="$" <?= $userData['currency'] === '$' ? 'selected' : '' ?>>USD ($)</option>
                            <option value="€" <?= $userData['currency'] === '€' ? 'selected' : '' ?>>EUR (€)</option>
                            <option value="£" <?= $userData['currency'] === '£' ? 'selected' : '' ?>>GBP (£)</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Preferences</button>
                </form>
            </div>

            <div class="glass-panel">
                <h3 style="margin-bottom: 16px;">Security & Password</h3>
                <form method="POST" action="settings.php">
                    <input type="hidden" name="change_password" value="1">
                    <div class="form-group">
                        <label>Current Password</label>
                        <input type="password" name="old_password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Update Password</button>
                </form>
            </div>
        </div>
    </main>
</div>
</body>
</html>