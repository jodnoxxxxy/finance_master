<?php
declare(strict_types=1);

/**
 * Reproducible unit checks for the hand-written DSA package.
 *
 * Run from the project root:
 *   php tests/dsa_test.php
 */

error_reporting(E_ALL);
require_once __DIR__ . '/../dsa/Stack.php';
require_once __DIR__ . '/../dsa/MinHeap.php';
require_once __DIR__ . '/../dsa/Trie.php';
require_once __DIR__ . '/../dsa/BST.php';
require_once __DIR__ . '/../dsa/HashMap.php';
require_once __DIR__ . '/../dsa/Sorting.php';
require_once __DIR__ . '/../dsa/Knapsack.php';
require_once __DIR__ . '/../dsa/Graph.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $ok): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "PASS  $label\n";
    } else {
        $failed++;
        echo "FAIL  $label\n";
    }
}

// ---------------------------------------------------------------- Stack / undo
$stack = new Stack();
$stack->push(1);
$stack->push(2);
check('Stack is LIFO', $stack->pop() === 2 && $stack->size() === 1);

$undo = new UndoManager(3);
for ($i = 0; $i < 5; $i++) {
    $undo->record(['i' => $i]);
}
check('Undo history is capped at max depth', $undo->undoCount() === 3);

// ------------------------------------------------------------------- Heap / TopK
$heap = new MinHeap();
foreach ([5, 1, 9, 3, 7] as $value) {
    $heap->insert($value);
}
check('MinHeap extracts the minimum first', $heap->extract() === 1);

$top = MinHeap::topK(
    [['a' => 5], ['a' => 1], ['a' => 9], ['a' => 3], ['a' => 7]],
    3,
    static function (array $a, array $b): int {
        return $a['a'] <=> $b['a'];
    }
);
check('TopK returns the 3 largest in descending order', array_column($top, 'a') === [9, 7, 5]);

// ------------------------------------------------------------------------ Trie
$trie = new Trie();
$trie->insertText('Swiggy dinner');
$trie->insertText('Swiggy lunch');
$trie->insertText('Sweet Mart');
$suggestions = $trie->autocomplete('sw', 5);
check('Trie prefix search finds merchant words', in_array('Swiggy', $suggestions, true));
check('Trie prefix search finds full descriptions', in_array('Sweet Mart', $suggestions, true));

// --------------------------------------------------------------------- AVL BST
$tree = new BST();
foreach ([10, 20, 30, 40, 50, 25] as $key) {
    $tree->insert($key, $key);
}
check('BST range query is inclusive', $tree->rangeQuery(22, 42) === [25, 30, 40]);
check('AVL tree remains balanced', $tree->isBalanced());
check('AVL height stays logarithmic', $tree->height() <= 4);

// ---------------------------------------------------------------------- HashMap
$map = new HashMap(7);
$map->addTo('food', 100);
$map->addTo('food', 50);
$map->put('rent', 500);
check('HashMap addTo accumulates values', abs((float)$map->get('food') - 150) < 0.001);
check('HashMap stores multiple keys', count($map->entries()) === 2);
$map->remove('food');
check('HashMap remove deletes the key', !$map->has('food') && $map->has('rent'));

// ---------------------------------------------------------------------- Sorting
$rows = [
    ['id' => 1, 'amount' => 3, 'date' => '2026-01-03'],
    ['id' => 2, 'amount' => 1, 'date' => '2026-01-01'],
    ['id' => 3, 'amount' => 2, 'date' => '2026-01-02'],
];
$amountCompare = static function (array $a, array $b): int {
    return $a['amount'] <=> $b['amount'];
};
foreach (['merge', 'quick', 'bubble', 'insertion'] as $algorithm) {
    $sorted = Sorting::sortWith($rows, $amountCompare, $algorithm);
    check("$algorithm sort returns ascending amounts", array_column($sorted, 'amount') === [1, 2, 3]);
}
$byDate = Sorting::sortBy($rows, 'date', 'DESC', 'merge');
check('sortBy handles date DESC', array_column($byDate, 'id') === [1, 3, 2]);

// ---------------------------------------------------------------- 0/1 Knapsack
$items = [
    ['name' => 'Shoes', 'cost' => 3000, 'priority' => 8],
    ['name' => 'Headphones', 'cost' => 4000, 'priority' => 6],
    ['name' => 'Books', 'cost' => 1000, 'priority' => 9],
    ['name' => 'Watch', 'cost' => 7000, 'priority' => 7],
    ['name' => 'Bag', 'cost' => 5000, 'priority' => 5],
];
$knapsack = Knapsack::solve($items, 15000);
check('Knapsack stays within budget', $knapsack['total_cost'] <= 15000.0);
check('Knapsack uses a DP formulation', in_array($knapsack['method'], ['capacity', 'value'], true));
$largeBudget = Knapsack::solve($items, 100000000);
check('Knapsack large-budget fallback stays within budget', $largeBudget['total_cost'] <= 100000000.0);

// ------------------------------------------------------------------ Debt graph
$cycle = new DebtGraph();
$cycle->addDebt('A', 'B', 1000);
$cycle->addDebt('B', 'C', 1000);
$cycle->addDebt('C', 'A', 1000);
check('Graph cancels a perfect cycle', $cycle->settle()['simplified_transfer_count'] === 0);

$graph = new DebtGraph();
$graph->addDebt('A', 'B', 500);
$graph->addDebt('C', 'B', 300);
$graph->addDebt('A', 'C', 200);
$settlement = $graph->settle();
check('Graph preserves net cash flow', abs((float)$settlement['simplified_total_flow'] - 800.0) < 0.01);
check('Graph reduces transfer count', $settlement['simplified_transfer_count'] <= 2);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
