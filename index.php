<?php
session_start();
require_once 'config/db.php';

$error = '';
$success = '';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

// Form Submission Engine
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($name) || empty($email) || empty($password)) {
            $error = 'All fields are strictly required!';
        } else {
            // Check if user already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'Account with this email already exists!';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
                if ($stmt->execute([$name, $email, $hash])) {
                    $userId = $pdo->lastInsertId();

                    // Seed Default Accounts & Categories for seamless onboarding
                    $pdo->prepare("INSERT INTO accounts (user_id, name, type, balance) VALUES (?, 'Primary Checking', 'Bank Account', 0.00), (?, 'Cash Wallet', 'Cash', 0.00)")->execute([$userId, $userId]);
                    
                    $categories = [
                        ['Salary', 'income', '#10b981'],
                        ['Investment', 'income', '#3b82f6'],
                        ['Food & Dining', 'expense', '#f59e0b'],
                        ['Rent & Bills', 'expense', '#ef4444'],
                        ['Entertainment', 'expense', '#8b5cf6'],
                        ['Shopping', 'expense', '#ec4899']
                    ];
                    $catStmt = $pdo->prepare("INSERT INTO categories (user_id, name, type, color) VALUES (?, ?, ?, ?)");
                    foreach ($categories as $cat) {
                        $catStmt->execute([$userId, $cat[0], $cat[1], $cat[2]]);
                    }

                    $success = 'Registration successful! You can now sign in.';
                } else {
                    $error = 'Failed to register account.';
                }
            }
        }
    } elseif ($action === 'login') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Never let one user's undo/redo history leak into another session.
            unset($_SESSION['undo_stack'], $_SESSION['redo_stack'], $_SESSION['wishlist_budget']);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_currency'] = $user['currency'];
            header("Location: dashboard.php");
            exit();
        } else {
            $error = 'Invalid email or password!';
        }
    }
}
?>
<?php include 'includes/header.php'; ?>

<!-- Floating Animated Currency Particles -->
<div class="floating-currency" style="left: 10%; animation-delay: 0s;">₹</div>
<div class="floating-currency" style="left: 30%; animation-delay: 3s;">$</div>
<div class="floating-currency" style="left: 55%; animation-delay: 7s;">€</div>
<div class="floating-currency" style="left: 78%; animation-delay: 1.5s;">£</div>
<div class="floating-currency" style="left: 90%; animation-delay: 5s;">%</div>

<div class="auth-wrapper">
    <div class="auth-box glass-panel">
        <div style="text-align: center; margin-bottom: 24px;">
            <div class="logo-badge" style="margin: 0 auto 12px; width: 48px; height: 48px; font-size: 1.6rem;">💎</div>
            <h2 style="font-weight: 800; font-size: 1.6rem;">FinCore Portal</h2>
            <p style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 4px;">Smart Wealth, Budget & Savings Tracker</p>
        </div>

        <?php if (!empty($error)): ?>
            <div style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid #ef4444; padding: 10px; border-radius: 8px; margin-bottom: 16px; font-size: 0.85rem; text-align: center;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid #10b981; padding: 10px; border-radius: 8px; margin-bottom: 16px; font-size: 0.85rem; text-align: center;">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <div class="tab-nav">
            <button class="tab-btn active" id="tab-login-btn" onclick="showAuthTab('login')">Sign In</button>
            <button class="tab-btn" id="tab-register-btn" onclick="showAuthTab('register')">Register</button>
        </div>

        <!-- Login Form -->
        <form id="form-login" method="POST" action="index.php">
            <input type="hidden" name="action" value="login">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="name@domain.com" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">Sign In to Dashboard →</button>
        </form>

        <!-- Register Form -->
        <form id="form-register" method="POST" action="index.php" style="display: none;">
            <input type="hidden" name="action" value="register">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" class="form-control" placeholder="John Doe" required>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="john@example.com" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" class="form-control" placeholder="Create a strong password" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">Create Free Account →</button>
        </form>
    </div>
</div>

<script>
function showAuthTab(tab) {
    if (tab === 'login') {
        document.getElementById('form-login').style.display = 'block';
        document.getElementById('form-register').style.display = 'none';
        document.getElementById('tab-login-btn').classList.add('active');
        document.getElementById('tab-register-btn').classList.remove('active');
    } else {
        document.getElementById('form-login').style.display = 'none';
        document.getElementById('form-register').style.display = 'block';
        document.getElementById('tab-register-btn').classList.add('active');
        document.getElementById('tab-login-btn').classList.remove('active');
    }
}
</script>
</body>
</html>