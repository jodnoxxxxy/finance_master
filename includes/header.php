<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$currency = $_SESSION['user_currency'] ?? '₹';
$userName = $_SESSION['user_name'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FinCore - Modern Personal Finance</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- Chart.js for High-End Financial Visualizations -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="bg-ambient"></div>