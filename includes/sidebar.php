<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<div class="sidebar">
    <div class="sidebar-header">
        <div class="logo-badge">💎</div>
        <div>
            <h3 style="font-size: 1.15rem; font-weight:800;">FinCore</h3>
            <span style="font-size: 0.72rem; color: var(--text-secondary);">Finance Manager</span>
        </div>
    </div>
    <ul class="sidebar-menu">
        <li><a href="dashboard.php" class="<?= $currentPage == 'dashboard.php' ? 'active' : '' ?>">📊 Dashboard</a></li>
        <li><a href="transactions.php" class="<?= $currentPage == 'transactions.php' ? 'active' : '' ?>">💳 Transactions</a></li>
        <li><a href="categories.php" class="<?= $currentPage == 'categories.php' ? 'active' : '' ?>">📂 Categories</a></li>
        <li><a href="budgets.php" class="<?= $currentPage == 'budgets.php' ? 'active' : '' ?>">🎯 Budgets</a></li>
        <li><a href="debts.php" class="<?= $currentPage == 'debts.php' ? 'active' : '' ?>">🤝 Debt Simplifier</a></li>
        <li><a href="goals.php" class="<?= $currentPage == 'goals.php' ? 'active' : '' ?>">🚀 Savings Goals</a></li>
        <li><a href="reports.php" class="<?= $currentPage == 'reports.php' ? 'active' : '' ?>">📈 Reports & CSV</a></li>
        <li><a href="benchmark.php" class="<?= $currentPage == 'benchmark.php' ? 'active' : '' ?>">⚡ DSA Benchmark</a></li>
        <li><a href="settings.php" class="<?= $currentPage == 'settings.php' ? 'active' : '' ?>">⚙️ Settings</a></li>
        <li style="margin-top: auto;"><a href="logout.php" style="color: var(--danger);">🚪 Sign Out</a></li>
    </ul>
</div>