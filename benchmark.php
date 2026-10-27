<?php
declare(strict_types=1);

require_once 'includes/auth_check.php';
require_once 'dsa/Sorting.php';
require_once 'dsa/BST.php';
require_once 'dsa/Trie.php';
require_once 'dsa/HashMap.php';
require_once 'dsa/MinHeap.php';

ini_set('memory_limit', '256M');
set_time_limit(120);

/**
 * Generate a reproducible synthetic ledger.
 *
 * @return array<int, array<string, mixed>>
 */
function benchmarkDataset(int $n, int $seed): array
{
    $merchants = [
        'Swiggy', 'Zomato', 'BigBasket', 'Amazon', 'Flipkart', 'Uber', 'Ola',
        'Netflix', 'Spotify', 'Local Cafe', 'Reliance Fresh', 'DMart',
        'BookMyShow', 'Indian Oil', 'Airtel', 'Jio', 'Croma', 'Decathlon',
        'Apollo Pharmacy', 'MakeMyTrip',
    ];
    $purposes = ['order', 'bill', 'recharge', 'purchase', 'trip', 'subscription'];
    $categories = ['Food & Dining', 'Shopping', 'Transport', 'Entertainment', 'Bills', 'Groceries', 'Health'];

    mt_srand($seed);
    $base = strtotime(date('Y-m-d'));
    $rows = [];

    for ($i = 1; $i <= $n; $i++) {
        $isExpense = mt_rand(1, 100) <= 85;
        $merchant = $merchants[mt_rand(0, count($merchants) - 1)];
        $purpose = $purposes[mt_rand(0, count($purposes) - 1)];
        $rows[] = [
            'id' => $i,
            'amount' => $isExpense
                ? mt_rand(1000, 500000) / 100
                : mt_rand(100000, 5000000) / 100,
            'date' => date('Y-m-d', $base - mt_rand(0, 730) * 86400),
            'description' => $merchant . ' ' . $purpose,
            'category' => $categories[mt_rand(0, count($categories) - 1)],
            'type' => $isExpense ? 'expense' : 'income',
        ];
    }

    return $rows;
}

/** @return array{ms: float, value: mixed} */
function timedCall(callable $fn): array
{
    $start = hrtime(true);
    $value = $fn();
    return [
        'ms' => (hrtime(true) - $start) / 1_000_000,
        'value' => $value,
    ];
}

/** @param array<int, array<string, mixed>> $rows */
function checksumRows(array $rows): float
{
    $sum = 0.0;
    foreach ($rows as $row) {
        $sum += (float)$row['amount'];
    }
    return round($sum, 2);
}

/** @param array<int, array<string, mixed>> $rows */
function isSortedByAmount(array $rows, callable $compare): bool
{
    $count = count($rows);
    for ($i = 1; $i < $count; $i++) {
        if ($compare($rows[$i - 1], $rows[$i]) > 0) {
            return false;
        }
    }
    return true;
}

/** @param array<int, array<string, mixed>> $rows */
function linearSearchById(array $rows, int $id): ?array
{
    foreach ($rows as $row) {
        if ((int)$row['id'] === $id) {
            return $row;
        }
    }
    return null;
}

/**
 * @param array<int, array<string, mixed>> $sortedRows Sorted by id asc.
 */
function binarySearchById(array $sortedRows, int $id): ?array
{
    $low = 0;
    $high = count($sortedRows) - 1;
    while ($low <= $high) {
        $mid = intdiv($low + $high, 2);
        $midId = (int)$sortedRows[$mid]['id'];
        if ($midId === $id) {
            return $sortedRows[$mid];
        }
        if ($midId < $id) {
            $low = $mid + 1;
        } else {
            $high = $mid - 1;
        }
    }
    return null;
}

$allowedSizes = [1000, 10000, 50000];
$results = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $n = (int)($_POST['n'] ?? 0);
    $seed = (int)($_POST['seed'] ?? 0);
    if (!in_array($n, $allowedSizes, true)) {
        $error = 'Choose a dataset size of 1,000, 10,000 or 50,000 rows.';
    } else {
        if ($seed <= 0) {
            $seed = random_int(1, 999999);
        }

        $generation = timedCall(static function () use ($n, $seed): array {
            return benchmarkDataset($n, $seed);
        });
        $dataset = $generation['value'];
        $generationMs = $generation['ms'];

        $amountCompare = static function (array $a, array $b): int {
            return (float)$a['amount'] <=> (float)$b['amount'];
        };
        $sourceChecksum = checksumRows($dataset);

        // ------------------------------------------------------ Sorting race
        $sortResults = [];

        $merge = timedCall(static function () use ($dataset, $amountCompare): array {
            return Sorting::mergeSort($dataset, $amountCompare);
        });
        $sortResults[] = [
            'label' => 'MergeSort',
            'tested_n' => $n,
            'ms' => $merge['ms'],
            'complexity' => 'O(N log N)',
            'space' => 'O(N)',
            'verified' => isSortedByAmount($merge['value'], $amountCompare)
                && abs(checksumRows($merge['value']) - $sourceChecksum) < 0.01,
            'note' => 'Full dataset · stable',
        ];
        unset($merge);

        $quick = timedCall(static function () use ($dataset, $amountCompare): array {
            return Sorting::quickSort($dataset, $amountCompare);
        });
        $sortResults[] = [
            'label' => 'QuickSort',
            'tested_n' => $n,
            'ms' => $quick['ms'],
            'complexity' => 'O(N log N) avg',
            'space' => 'O(log N)',
            'verified' => isSortedByAmount($quick['value'], $amountCompare)
                && abs(checksumRows($quick['value']) - $sourceChecksum) < 0.01,
            'note' => 'Full dataset · median-of-three',
        ];
        unset($quick);

        // Bubble sort is quadratic: cap the sample so the page stays usable.
        $bubbleN = min($n, 2000);
        $bubbleSample = array_slice($dataset, 0, $bubbleN);
        $bubbleChecksum = checksumRows($bubbleSample);
        $bubble = timedCall(static function () use ($bubbleSample, $amountCompare): array {
            return Sorting::bubbleSort($bubbleSample, $amountCompare);
        });
        $sortResults[] = [
            'label' => $bubbleN < $n ? 'BubbleSort*' : 'BubbleSort',
            'tested_n' => $bubbleN,
            'ms' => $bubble['ms'],
            'complexity' => 'O(N²)',
            'space' => 'O(1)',
            'verified' => isSortedByAmount($bubble['value'], $amountCompare)
                && abs(checksumRows($bubble['value']) - $bubbleChecksum) < 0.01,
            'note' => $bubbleN < $n ? 'Subset of ' . number_format($bubbleN) . ' rows' : 'Full dataset',
        ];
        unset($bubble, $bubbleSample);

        // ------------------------------------------------------- Search race
        $queryCount = max(50, min(500, intdiv(1500000, $n)));
        mt_srand($seed + 7);
        $queryIds = [];
        for ($i = 0; $i < $queryCount; $i++) {
            $queryIds[] = mt_rand(1, $n);
        }

        $sortedBuild = timedCall(static function () use ($dataset): array {
            return Sorting::mergeSort($dataset, static function (array $a, array $b): int {
                return (int)$a['id'] <=> (int)$b['id'];
            });
        });
        $sortedById = $sortedBuild['value'];

        $bstBuild = timedCall(static function () use ($dataset): BST {
            $tree = new BST();
            foreach ($dataset as $row) {
                $tree->insert((int)$row['id'], $row);
            }
            return $tree;
        });
        /** @var BST $bst */
        $bst = $bstBuild['value'];

        $hashBuild = timedCall(static function () use ($dataset, $n): HashMap {
            $map = new HashMap(max(101, $n));
            foreach ($dataset as $row) {
                $map->put((string)$row['id'], $row);
            }
            return $map;
        });
        /** @var HashMap $hashMap */
        $hashMap = $hashBuild['value'];

        $trieBuild = timedCall(static function () use ($dataset): Trie {
            $trie = new Trie();
            foreach ($dataset as $row) {
                $trie->insertText((string)$row['description']);
            }
            return $trie;
        });
        /** @var Trie $trie */
        $trie = $trieBuild['value'];

        $searchResults = [];

        $linearRun = timedCall(static function () use ($dataset, $queryIds): int {
            $found = 0;
            foreach ($queryIds as $id) {
                if (linearSearchById($dataset, $id) !== null) {
                    $found++;
                }
            }
            return $found;
        });
        $searchResults[] = [
            'label' => 'Linear Search',
            'structure' => 'Raw array scan',
            'total_ms' => $linearRun['ms'],
            'avg_us' => ($linearRun['ms'] * 1000) / $queryCount,
            'queries' => $queryCount,
            'complexity' => 'O(N)',
            'verified' => $linearRun['value'] === $queryCount,
        ];

        $binaryRun = timedCall(static function () use ($sortedById, $queryIds): int {
            $found = 0;
            foreach ($queryIds as $id) {
                if (binarySearchById($sortedById, $id) !== null) {
                    $found++;
                }
            }
            return $found;
        });
        $searchResults[] = [
            'label' => 'Binary Search',
            'structure' => 'Sorted array',
            'total_ms' => $binaryRun['ms'],
            'avg_us' => ($binaryRun['ms'] * 1000) / $queryCount,
            'queries' => $queryCount,
            'complexity' => 'O(log N)',
            'verified' => $binaryRun['value'] === $queryCount,
        ];

        $bstRun = timedCall(static function () use ($bst, $queryIds): int {
            $found = 0;
            foreach ($queryIds as $id) {
                if (count($bst->search($id)) > 0) {
                    $found++;
                }
            }
            return $found;
        });
        $searchResults[] = [
            'label' => 'BST Search',
            'structure' => 'AVL tree (id key)',
            'total_ms' => $bstRun['ms'],
            'avg_us' => ($bstRun['ms'] * 1000) / $queryCount,
            'queries' => $queryCount,
            'complexity' => 'O(log N)',
            'verified' => $bstRun['value'] === $queryCount,
        ];

        $hashRun = timedCall(static function () use ($hashMap, $queryIds): int {
            $found = 0;
            foreach ($queryIds as $id) {
                if ($hashMap->get((string)$id) !== null) {
                    $found++;
                }
            }
            return $found;
        });
        $searchResults[] = [
            'label' => 'HashMap Lookup',
            'structure' => 'Chained hash table',
            'total_ms' => $hashRun['ms'],
            'avg_us' => ($hashRun['ms'] * 1000) / $queryCount,
            'queries' => $queryCount,
            'complexity' => 'O(1) avg',
            'verified' => $hashRun['value'] === $queryCount,
        ];

        mt_srand($seed + 11);
        $prefixes = [];
        for ($i = 0; $i < $queryCount; $i++) {
            $sample = $dataset[mt_rand(0, $n - 1)];
            $prefixes[] = substr((string)$sample['description'], 0, 3);
        }
        $trieRun = timedCall(static function () use ($trie, $prefixes): int {
            $found = 0;
            foreach ($prefixes as $prefix) {
                if (count($trie->autocomplete($prefix, 5)) > 0) {
                    $found++;
                }
            }
            return $found;
        });
        $searchResults[] = [
            'label' => 'Trie Prefix',
            'structure' => 'Prefix tree',
            'total_ms' => $trieRun['ms'],
            'avg_us' => ($trieRun['ms'] * 1000) / $queryCount,
            'queries' => $queryCount,
            'complexity' => 'O(L)',
            'verified' => $trieRun['value'] === $queryCount,
        ];

        $results = [
            'n' => $n,
            'seed' => $seed,
            'generation_ms' => $generationMs,
            'sort' => $sortResults,
            'search' => $searchResults,
            'build' => [
                'id_merge_sort' => $sortedBuild['ms'],
                'bst' => $bstBuild['ms'],
                'hash_map' => $hashBuild['ms'],
                'trie' => $trieBuild['ms'],
            ],
            'bst_height' => $bst->height(),
            'hash_stats' => $hashMap->stats(),
            'trie_nodes' => $trie->countNodes(),
            'query_count' => $queryCount,
        ];
    }
}

$sortChartLabels = $results ? json_encode(array_column($results['sort'], 'label')) : '[]';
$sortChartData = $results ? json_encode(array_map(static function (array $row): float {
    return round($row['ms'], 4);
}, $results['sort'])) : '[]';
$searchChartLabels = $results ? json_encode(array_column($results['search'], 'label')) : '[]';
$searchChartData = $results ? json_encode(array_map(static function (array $row): float {
    return round($row['avg_us'], 4);
}, $results['search'])) : '[]';
?>
<?php include 'includes/header.php'; ?>

<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <header class="top-navbar">
            <div>
                <h1 style="font-size: 1.8rem; font-weight: 800;">DSA Benchmark Lab</h1>
                <p style="color: var(--text-secondary); font-size: 0.9rem;">Measure the hand-written algorithms against growing datasets</p>
            </div>
            <span class="dsa-chip">No SQL ORDER BY · No usort()</span>
        </header>

        <div class="glass-panel" style="margin-bottom: 24px;">
            <h3 style="margin-bottom: 14px;">Generate a Dataset</h3>
            <form method="POST" action="benchmark.php" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
                <div class="form-group" style="margin: 0; min-width: 190px;">
                    <label>Rows (N)</label>
                    <select name="n" class="form-control">
                        <?php foreach ($allowedSizes as $size): ?>
                            <option value="<?= $size ?>" <?= ($results['n'] ?? 0) === $size ? 'selected' : '' ?>><?= number_format($size) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin: 0; min-width: 180px;">
                    <label>Seed (optional)</label>
                    <input type="number" name="seed" class="form-control" placeholder="random" value="<?= htmlspecialchars((string)($results['seed'] ?? '')) ?>">
                </div>
                <button type="submit" class="btn btn-primary">⚡ Run Full Benchmark</button>
                <span style="color: var(--text-secondary); font-size: 0.78rem; padding-bottom: 12px;">
                    Generated in memory · nothing is written to your ledger
                </span>
            </form>
            <?php if ($error !== ''): ?>
                <div class="dsa-alert dsa-alert-error" style="margin-top: 14px; margin-bottom: 0;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
        </div>

        <?php if ($results === null): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px;">
                <div class="glass-panel">
                    <h3 style="margin-bottom: 10px;">🧮 Sorting Race</h3>
                    <p style="color: var(--text-secondary); font-size: 0.85rem;">MergeSort, QuickSort and BubbleSort run on the same generated rows, then the output order and checksum are verified.</p>
                </div>
                <div class="glass-panel">
                    <h3 style="margin-bottom: 10px;">🔎 Search Race</h3>
                    <p style="color: var(--text-secondary); font-size: 0.85rem;">Linear scan vs binary search vs AVL tree vs hash map vs Trie prefix lookup, averaged over repeated queries.</p>
                </div>
                <div class="glass-panel">
                    <h3 style="margin-bottom: 10px;">📈 Complexity Evidence</h3>
                    <p style="color: var(--text-secondary); font-size: 0.85rem;">Run 1,000 → 10,000 → 50,000 rows and watch the N² curve pull away from the N log N curves.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="dsa-chip-row" style="margin-bottom: 20px;">
                <span class="dsa-chip">N = <?= number_format($results['n']) ?></span>
                <span class="dsa-chip">Seed <?= (int)$results['seed'] ?></span>
                <span class="dsa-chip">Generated in <?= number_format($results['generation_ms'], 2) ?> ms</span>
                <span class="dsa-chip"><?= number_format($results['query_count']) ?> search queries</span>
                <span class="dsa-chip">BST height <?= (int)$results['bst_height'] ?></span>
                <span class="dsa-chip">Trie <?= number_format($results['trie_nodes']) ?> nodes</span>
            </div>

            <div style="display: grid; grid-template-columns: 1.15fr 1fr; gap: 24px; margin-bottom: 24px;">
                <div class="glass-panel">
                    <h3 style="font-weight: 700; margin-bottom: 10px;">Sorting Execution Time</h3>
                    <canvas id="sortChart" style="max-height: 260px;"></canvas>
                    <p style="color: var(--text-secondary); font-size: 0.72rem; margin-top: 10px;">
                        BubbleSort uses the full dataset at 1,000 rows and a capped 2,000-row subset at 10,000 / 50,000 rows because O(N²) would take minutes.
                    </p>
                </div>
                <div class="glass-panel">
                    <h3 style="font-weight: 700; margin-bottom: 10px;">Algorithm Correctness</h3>
                    <table class="custom-table" style="margin-top: 0;">
                        <thead><tr><th>Algorithm</th><th>Rows</th><th>Time</th><th>Order</th><th>Checksum</th></tr></thead>
                        <tbody>
                            <?php foreach ($results['sort'] as $row): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?= htmlspecialchars($row['label']) ?></td>
                                    <td><?= number_format((int)$row['tested_n']) ?></td>
                                    <td><?= number_format((float)$row['ms'], 3) ?> ms</td>
                                    <td><?= $row['verified'] ? '✅' : '❌' ?></td>
                                    <td><?= $row['verified'] ? 'match' : 'mismatch' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="color: var(--text-secondary); font-size: 0.72rem; margin-top: 10px;">
                        Every sorter must return an ascending array with the same amount checksum as the input.
                    </p>
                </div>
            </div>

            <div class="glass-panel" style="margin-bottom: 24px;">
                <h3 style="font-weight: 700; margin-bottom: 10px;">Sorting Complexity Comparison</h3>
                <div style="overflow-x: auto;">
                    <table class="custom-table" style="margin-top: 0;">
                        <thead>
                            <tr><th>Algorithm</th><th>Tested N</th><th>Time (ms)</th><th>Average Complexity</th><th>Auxiliary Space</th><th>Note</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results['sort'] as $row): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?= htmlspecialchars($row['label']) ?></td>
                                    <td><?= number_format((int)$row['tested_n']) ?></td>
                                    <td style="font-weight: 700; color: var(--warning);"><?= number_format((float)$row['ms'], 3) ?></td>
                                    <td><?= htmlspecialchars($row['complexity']) ?></td>
                                    <td><?= htmlspecialchars($row['space']) ?></td>
                                    <td style="color: var(--text-secondary);"><?= htmlspecialchars($row['note']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1.15fr 1fr; gap: 24px; margin-bottom: 24px;">
                <div class="glass-panel">
                    <h3 style="font-weight: 700; margin-bottom: 10px;">Average Search Time per Query (µs, log scale)</h3>
                    <canvas id="searchChart" style="max-height: 260px;"></canvas>
                    <p style="color: var(--text-secondary); font-size: 0.72rem; margin-top: 10px;">
                        Keys are inserted in ascending order, which would degenerate a plain BST; the AVL rotations keep the height at O(log N) (see the chip above).
                    </p>
                </div>
                <div class="glass-panel">
                    <h3 style="font-weight: 700; margin-bottom: 12px;">Structure Build Cost</h3>
                    <div class="dsa-metric-list">
                        <div><span>Sorted array (MergeSort by id)</span><strong><?= number_format((float)$results['build']['id_merge_sort'], 3) ?> ms</strong></div>
                        <div><span>AVL tree</span><strong><?= number_format((float)$results['build']['bst'], 3) ?> ms</strong></div>
                        <div><span>HashMap</span><strong><?= number_format((float)$results['build']['hash_map'], 3) ?> ms</strong></div>
                        <div><span>Trie</span><strong><?= number_format((float)$results['build']['trie'], 3) ?> ms</strong></div>
                        <div><span>Hash load factor</span><strong><?= number_format((float)$results['hash_stats']['load_factor'] * 100, 1) ?>%</strong></div>
                        <div><span>Hash capacity</span><strong><?= number_format((int)$results['hash_stats']['capacity']) ?></strong></div>
                        <div><span>Hash collision chains</span><strong><?= number_format((int)$results['hash_stats']['collisions']) ?></strong></div>
                        <div><span>Max chain length</span><strong><?= number_format((int)$results['hash_stats']['max_chain']) ?></strong></div>
                        <div><span>Trie nodes</span><strong><?= number_format((int)$results['trie_nodes']) ?></strong></div>
                    </div>
                </div>
            </div>

            <div class="glass-panel" style="margin-bottom: 24px;">
                <h3 style="font-weight: 700; margin-bottom: 10px;">Search Complexity Comparison</h3>
                <div style="overflow-x: auto;">
                    <table class="custom-table" style="margin-top: 0;">
                        <thead>
                            <tr><th>Algorithm</th><th>Structure</th><th>Queries</th><th>Avg µs/query</th><th>Total ms</th><th>Complexity</th><th>Verified</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results['search'] as $row): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?= htmlspecialchars($row['label']) ?></td>
                                    <td><?= htmlspecialchars($row['structure']) ?></td>
                                    <td><?= number_format((int)$row['queries']) ?></td>
                                    <td style="font-weight: 700; color: var(--warning);"><?= number_format((float)$row['avg_us'], 4) ?></td>
                                    <td><?= number_format((float)$row['total_ms'], 3) ?></td>
                                    <td><?= htmlspecialchars($row['complexity']) ?></td>
                                    <td><?= $row['verified'] ? '✅' : '❌' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="glass-panel">
            <h3 style="font-weight: 700; margin-bottom: 10px;">Full Project Complexity Sheet</h3>
            <div style="overflow-x: auto;">
                <table class="custom-table" style="margin-top: 0;">
                    <thead>
                        <tr><th>Feature</th><th>Structure / Algorithm</th><th>Best</th><th>Average</th><th>Worst</th><th>Auxiliary Space</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Undo / Redo</td><td>Two stacks (LIFO)</td><td>O(1)</td><td>O(1)</td><td>O(1)</td><td>O(N)</td></tr>
                        <tr><td>Top-K expenses</td><td>Bounded Min-Heap</td><td>O(N log K)</td><td>O(N log K)</td><td>O(N log K)</td><td>O(K)</td></tr>
                        <tr><td>Autocomplete</td><td>Trie</td><td>O(L)</td><td>O(L + S)</td><td>O(L + S)</td><td>O(total chars)</td></tr>
                        <tr><td>Date range filter</td><td>AVL BST range traversal</td><td>O(log N + M)</td><td>O(log N + M)</td><td>O(log N + M)</td><td>O(N)</td></tr>
                        <tr><td>Category aggregation</td><td>HashMap + separate chaining</td><td>O(1)</td><td>O(1)</td><td>O(N)</td><td>O(N)</td></tr>
                        <tr><td>Budget optimizer</td><td>0/1 Knapsack DP</td><td>O(NW)</td><td>O(NW)</td><td>O(NW)</td><td>O(NW)</td></tr>
                        <tr><td>Ledger sorter</td><td>MergeSort / QuickSort</td><td>O(N log N)</td><td>O(N log N)</td><td>O(N²) for QuickSort</td><td>O(N) / O(log N)</td></tr>
                        <tr><td>Debt settlement</td><td>Graph + greedy heaps</td><td>O(V log V + E)</td><td>O(V log V + E)</td><td>O(V log V + E)</td><td>O(V + E)</td></tr>
                    </tbody>
                </table>
            </div>
            <p style="color: var(--text-secondary); font-size: 0.72rem; margin-top: 12px;">
                L = prefix length, S = suggestions returned, M = range matches, K = Top-K size, W = budget capacity, V/E = graph vertices/edges.
            </p>
        </div>
    </main>
</div>

<?php if ($results !== null): ?>
<script>
const sortCtx = document.getElementById('sortChart').getContext('2d');
new Chart(sortCtx, {
    type: 'bar',
    data: {
        labels: <?= $sortChartLabels ?>,
        datasets: [{
            label: 'Time (ms)',
            data: <?= $sortChartData ?>,
            backgroundColor: ['rgba(99,102,241,0.8)', 'rgba(16,185,129,0.8)', 'rgba(239,68,68,0.8)'],
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(255,255,255,0.05)' } },
            y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(255,255,255,0.05)' }, title: { display: true, text: 'milliseconds', color: '#94a3b8' } }
        }
    }
});

const searchCtx = document.getElementById('searchChart').getContext('2d');
new Chart(searchCtx, {
    type: 'bar',
    data: {
        labels: <?= $searchChartLabels ?>,
        datasets: [{
            label: 'Avg µs / query',
            data: <?= $searchChartData ?>,
            backgroundColor: ['rgba(239,68,68,0.8)', 'rgba(245,158,11,0.8)', 'rgba(99,102,241,0.8)', 'rgba(16,185,129,0.8)', 'rgba(96,165,250,0.8)'],
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(255,255,255,0.05)' } },
            y: {
                type: 'logarithmic',
                ticks: { color: '#94a3b8' },
                grid: { color: 'rgba(255,255,255,0.05)' },
                title: { display: true, text: 'microseconds (log scale)', color: '#94a3b8' }
            }
        }
    }
});
</script>
<?php endif; ?>
</body>
</html>
