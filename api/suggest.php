<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../dsa/Trie.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$userId = (int)$_SESSION['user_id'];
$query = trim((string)($_GET['q'] ?? ''));

if ($query === '') {
    echo json_encode([
        'prefix' => '',
        'suggestions' => [],
        'trie_nodes' => 0,
        'trie_words' => 0,
        'build_us' => 0.0,
        'lookup_us' => 0.0,
    ]);
    exit();
}

$stmt = $pdo->prepare("SELECT description FROM transactions WHERE user_id = ? AND description IS NOT NULL AND description <> ''");
$stmt->execute([$userId]);
$descriptions = [];
while ($row = $stmt->fetch()) {
    $descriptions[] = (string)$row['description'];
}

$buildStart = hrtime(true);
$trie = new Trie();
foreach ($descriptions as $description) {
    $trie->insertText($description);
}
$buildUs = (hrtime(true) - $buildStart) / 1000;

$lookupStart = hrtime(true);
$suggestions = $trie->autocompleteDetailed($query, 8);
$lookupUs = (hrtime(true) - $lookupStart) / 1000;

echo json_encode([
    'prefix' => $query,
    'suggestions' => $suggestions,
    'trie_nodes' => $trie->countNodes(),
    'trie_words' => $trie->countWords(),
    'build_us' => round($buildUs, 2),
    'lookup_us' => round($lookupUs, 2),
]);
