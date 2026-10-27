# FinCore — DSA Mini-Project Report

**Project:** FinCore Personal Finance Manager  
**Course component:** Data Structures & Algorithms mini-project  
**Stack:** PHP 8.2, MySQL, Chart.js, custom HTML/CSS  
**Core claim:** Every high-value data operation in the ledger is performed by a
hand-written data structure or algorithm, not delegated to SQL
`ORDER BY`, `SUM`, `GROUP BY` or `LIKE`, and not delegated to PHP's
`usort()` / `sort()`.

---

## 1. Problem Statement

Traditional CRUD finance applications push the interesting work into the
database:

```sql
SELECT * FROM transactions WHERE user_id = ? ORDER BY amount DESC;
SELECT category_id, SUM(amount) FROM transactions GROUP BY category_id;
SELECT * FROM transactions WHERE description LIKE '%sw%';
```

This makes the application a thin form over SQL. It also hides the algorithmic
trade-offs that matter when the ledger grows:

- Sorting the entire ledger just to show five large expenses is wasteful.
- `LIKE '%prefix%'` forces a full scan and cannot use a normal index.
- Recomputing aggregate totals by hand is error-prone.
- A flat expense list cannot answer "what should I buy with ₹15,000?"
- A list of IOUs between friends contains cycles that require graph reasoning.
- A delete operation cannot be reversed without a history structure.

The project therefore keeps the existing UI and database persistence, but moves
the **decision-making layer** into a `dsa/` package implemented from scratch.

---

## 2. Feature-to-DSA Mapping

| # | Finance feature | Data structure / algorithm | Where it is used | Complexity |
|---|-----------------|----------------------------|------------------|------------|
| 1 | Multi-level Undo / Redo | Two LIFO stacks (`dsa/Stack.php`) | `transactions.php`, `includes/undo_engine.php`, dashboard navbar | Push/pop **O(1)** |
| 2 | Top-K largest expenses | Bounded Min-Heap (`dsa/MinHeap.php`) | `dashboard.php` Top-5 widget | **O(N log K)**, space **O(K)** |
| 3 | Merchant / description autocomplete | Trie / prefix tree (`dsa/Trie.php`) | `api/suggest.php` + `transactions.php` | **O(L)** prefix walk |
| 4 | Date-range timeline filter | AVL-balanced BST (`dsa/BST.php`) | `transactions.php` date range | **O(log N + M)** |
| 5 | Category aggregator | Hash table + separate chaining (`dsa/HashMap.php`) | `dashboard.php`, `budgets.php`, `reports.php` | **O(1)** average |
| 6 | Wishlist / budget optimizer | 0/1 Knapsack DP (`dsa/Knapsack.php`) | `budgets.php` optimizer tab | **O(N × W)** |
| 7 | Multi-criteria ledger sorter | MergeSort, QuickSort, BubbleSort, InsertionSort (`dsa/Sorting.php`) | `transactions.php`, dashboard, reports, goals, categories | **O(N log N)** |
| 8 | Debt settlement simplifier | Directed graph + greedy heap matching (`dsa/Graph.php`) | `debts.php` | **O(V log V + E)** |
| 9 | Live algorithm evidence | Benchmark harness (`benchmark.php`) | Sidebar → DSA Benchmark | Measures 1k / 10k / 50k rows |

---

## 3. Data Structure Selection Rationale

### 3.1 Why a heap for Top-K instead of sorting everything?

**Naive approach:** sort all `N` expenses, then take the first `K`.

- Time: `O(N log N)`
- Space: `O(N)` (or a full sorted copy)

**Heap approach:** keep a min-heap of exactly `K` items. The root is the
*weakest* of the current winners. A new expense replaces the root only if it is
larger.

- Time: `O(N log K)`
- Space: `O(K)`

For `N = 50,000` and `K = 5`, this is roughly `50,000 × log₂5` heap operations
instead of sorting 50,000 rows. The dashboard only needs five rows, so sorting
the rest is wasted work.

### 3.2 Why a Trie instead of `LIKE '%query%'`?

`LIKE '%sw%'` cannot use a B-tree index because the wildcard is on the left.
The database scans every row and inspects every description.

A Trie stores characters on edges. Looking up `"sw"` walks two nodes, then
collects the subtree beneath that prefix. The cost depends on the **prefix
length L** and the number of suggestions `S`, not on the total number of rows.

The project inserts both the full description and individual merchant tokens,
so typing `"sw"` suggests both `Swiggy` and `Swiggy dinner`.

### 3.3 Why 0/1 Knapsack for the wishlist?

The wishlist items are indivisible: you buy the shoes or you do not. This is a
**0/1 knapsack**, not the fractional knapsack solved by a value/cost greedy
ratio. The DP table evaluates every capacity from `0` to `W` and keeps the
maximum utility achievable at that capacity.

- Capacity DP: `O(N × W)` — used for normal wishlists.
- Value-indexed fallback: `O(N × ΣV)` — used when the budget is very large and
  an `N × W` table would be too big.
- The solver reconstructs the actual chosen subset, not just the maximum value.

### 3.4 Why an AVL tree for date ranges?

A plain BST can degenerate into a linked list if rows arrive in sorted order.
The implementation performs AVL rotations after every insert, so the height stays
`O(log N)`. In-order traversal prunes subtrees outside `[from, to]`, giving
`O(log N + M)` for `M` matching rows instead of an `O(N)` scan.

### 3.5 Why a hash map with separate chaining?

Category totals need fast `insert` and `lookup` by category id. A hash table
gives average `O(1)`. The implementation uses:

- polynomial rolling hash: `h = (h × 31 + ASCII) mod capacity`
- separate chaining with linked lists
- prime capacities
- automatic resize when the load factor exceeds `0.75`
- collision and chain-length statistics exposed to the UI

Separate chaining is easier to demonstrate in a viva than open addressing and
handles high load factors gracefully.

### 3.6 Why two stacks for Undo/Redo?

Undo is inherently LIFO. Each mutation pushes a complete action snapshot onto
the undo stack. Undo pops it, applies the inverse, and pushes the same action
onto the redo stack. Any new action clears the redo stack. All operations are
`O(1)`.

### 3.7 Why a graph for debt settlement?

Friends' IOUs form a directed weighted graph. After computing each person's net
balance (`received − paid`), cycles cancel automatically. The greedy matcher
repeatedly pairs the **largest creditor** with the **largest debtor** using two
max-heaps. This produces at most `V − 1` transfers and runs in `O(V log V + E)`.

---

## 4. Implementation Map

```
finance_master/
├── dsa/
│   ├── Stack.php        # Stack + UndoManager (undo/redo stacks)
│   ├── MinHeap.php      # MinHeap + bounded TopK
│   ├── Trie.php         # TrieNode + Trie autocomplete
│   ├── BST.php          # AVL-balanced BST with range query
│   ├── HashMap.php      # chained hash table + rolling hash
│   ├── Sorting.php      # merge/quick/bubble/insertion sort
│   ├── Knapsack.php     # 0/1 DP (capacity + value fallback)
│   └── Graph.php        # DebtGraph + greedy settlement
├── api/
│   └── suggest.php      # live Trie lookup endpoint
├── config/
│   ├── db.php           # persistence only
│   └── dsa_schema.php   # wishlist_items + debts tables
├── includes/
│   ├── undo_engine.php  # executes stack actions against MySQL
│   └── undo_controls.php
├── benchmark.php        # dataset generator + timing lab
├── tests/
│   └── dsa_test.php     # 23 CLI assertions for every structure
├── dashboard.php        # HashMap + TopK + MergeSort
├── transactions.php     # Stack undo/redo + BST range + Trie UI + sorting
├── budgets.php          # Knapsack optimizer + HashMap spent totals
├── debts.php            # graph settlement UI
└── reports.php          # HashMap aggregation + CSV MergeSort
```

---

## 5. SQL → Custom Algorithm Replacements

| Before (database-delegated) | After (custom implementation) |
|---|---|
| `ORDER BY t.date DESC` | `Sorting::sortBy($rows, 'date', 'DESC', 'merge')` |
| `ORDER BY amount DESC` | `Sorting::sortBy($rows, 'amount', 'DESC', $algorithm)` |
| `SUM(CASE WHEN type='income' ...)` | `HashMap::addTo('income', $amount)` |
| `GROUP BY DATE_FORMAT(date, '%Y-%m')` | `HashMap` keyed by `Y-m`, then MergeSort |
| `GROUP BY c.id` in reports | `HashMap` keyed by category id |
| correlated `SUM(amount)` subquery for budgets | one `HashMap` keyed by `category_id|YYYY-MM` |
| `ORDER BY deadline ASC` | `Sorting::sortBy($goals, 'deadline', 'ASC', 'merge')` |
| `ORDER BY type, name` | custom two-key MergeSort comparator |
| `LIKE '%query%'` | Trie prefix lookup `O(L)` |
| Top-5 via full sort | bounded Min-Heap `O(N log K)` |

`WHERE user_id = ?` remains in the persistence queries. This is an **access
control boundary**, not an algorithmic filter; removing it would leak another
user's ledger. The DSA filtering (date range, prefix search, sorting,
aggregation) is performed in PHP after the authorised rows are loaded.

---

## 6. Complexity Analysis

| Structure / algorithm | Best | Average | Worst | Auxiliary space |
|---|---|---|---|---|
| Stack push/pop | O(1) | O(1) | O(1) | O(N) for N actions |
| Min-Heap insert/extract | O(1) / O(log K) | O(log K) | O(log K) | O(K) |
| Top-K over N items | O(N) if already ordered | O(N log K) | O(N log K) | O(K) |
| Trie insert | O(L) | O(L) | O(L) | O(total characters) |
| Trie prefix lookup | O(L) | O(L + S) | O(L + S) | O(S) recursion/stack |
| AVL search/insert | O(log N) | O(log N) | O(log N) | O(N) |
| AVL range query | O(log N + M) | O(log N + M) | O(log N + M) | O(M) output |
| HashMap lookup | O(1) | O(1) | O(N) | O(N) |
| HashMap insert | O(1) | O(1) amortised | O(N) | O(N) |
| MergeSort | O(N log N) | O(N log N) | O(N log N) | O(N) |
| QuickSort | O(N log N) | O(N log N) | O(N²) | O(log N) |
| BubbleSort | O(N) | O(N²) | O(N²) | O(1) |
| 0/1 Knapsack | O(NW) | O(NW) | O(NW) | O(NW) |
| Graph settlement | O(V log V + E) | O(V log V + E) | O(V log V + E) | O(V + E) |

Definitions: `L` prefix length, `S` suggestions returned, `M` range matches,
`K` Top-K size, `W` budget capacity, `V/E` graph vertices/edges, `N` rows.

---

## 7. Benchmark Methodology

`benchmark.php` performs the following in one request:

1. **Generate** `N ∈ {1,000, 10,000, 50,000}` synthetic transactions with a
   reproducible seed. Nothing is written to the user's ledger.
2. **Sorting race**
   - MergeSort on all `N`
   - QuickSort on all `N`
   - BubbleSort on `min(N, 2,000)` because `O(N²)` at 50k would take minutes
   - Verify each output is ascending and has the same amount checksum as the
     input.
3. **Search race**
   - Linear scan `O(N)`
   - Manual binary search `O(log N)`
   - AVL tree search `O(log N)`
   - HashMap lookup `O(1)` average
   - Trie prefix lookup `O(L)`
   - Average over 50–500 queries depending on `N`, so the page stays responsive.
4. **Visualise** sorting time and search time with Chart.js.
5. **Print** the full project complexity sheet.

Expected shape of the results (actual numbers vary by machine). The values
below were measured on the development machine (PHP 8.2, Windows/XAMPP):

| N | MergeSort | QuickSort | BubbleSort | Linear search | Binary search | AVL search | HashMap |
|---|---|---|---|---|---|---|---|
| 1,000 | 14.9 ms | 6.4 ms | 264 ms | 71.4 µs/query | 3.60 µs/query | 1.85 µs/query | 16.1 µs/query |
| 10,000 | 84.1 ms | 108.1 ms | 970 ms (2k subset) | 376.0 µs/query | 4.60 µs/query | 2.32 µs/query | 1.49 µs/query |
| 50,000 | 674.0 ms | 512.4 ms | 1,149 ms (2k subset) | 1,532.2 µs/query | 5.81 µs/query | 2.97 µs/query | 1.47 µs/query |

The key evidence for the viva is the **growth rate**: as `N` grows 10×, linear
search grows about 10× (71 → 376 → 1,532 µs), while binary search, AVL search
and hash lookup stay almost flat. Bubble sort on its capped subset is already
slower than MergeSort/QuickSort on 50,000 rows. MergeSort/QuickSort grow close
to the expected `N log N` curve.

A second useful proof point: the benchmark inserts keys `1..N` in **sorted
order**, which would turn a plain BST into a linked list. The AVL tree reports a
height of **16 for 50,000 nodes**, matching the expected `⌈log₂ 50000⌉ = 16`.

> Take screenshots at 1,000 / 10,000 / 50,000 and plot the exported numbers in
> Excel or Google Sheets for the report. The page deliberately shows the raw
> timings so the examiner can reproduce them.

---

## 8. How to Demonstrate (Suggested Viva Script)

1. **Register / log in** and add 6–10 transactions with descriptions such as
   `Swiggy dinner`, `Swiggy lunch`, `BigBasket groceries`, `Amazon order`.
2. **Trie:** start typing `Sw` in the description box. Show the live suggestion
   dropdown and the “Trie lookup · X µs” footer.
3. **Undo/Redo:** delete a transaction, click **Undo** in the navbar, show the
   wallet balance restored. Click **Redo** to delete it again. Do two actions
   and undo twice to prove multi-level LIFO behaviour.
4. **BST range:** pick a `From` and `To` date in the ledger filter. Point out
   “BST range active” and the reduced row count.
5. **Sorting:** change sort field to `amount`, direction to `DESC`, and switch
   between MergeSort and QuickSort. Point out the measured milliseconds.
6. **Top-K:** open the dashboard. Show the Top-5 largest expenses and the
   “Min-Heap · O(N log K)” chip.
7. **HashMap:** open Reports. Show the category breakdown and the hash-table
   stats (load factor, collisions, max chain).
8. **Knapsack:** open Budgets → Wishlist Optimizer. Add shoes ₹3,000/8,
   headphones ₹4,000/6, books ₹1,000/9, watch ₹7,000/7. Set cap ₹15,000. Show
   the selected vs deferred items and the DP cell count.
9. **Graph:** open Debt Simplifier. Add A→B ₹1,000, B→C ₹1,000, C→A ₹1,000.
   Show that the simplified transfer list is empty. Add a non-cyclic debt to
   show a real transfer.
10. **Benchmark:** run 1,000, then 10,000, then 50,000. Show the sorting and
    search charts and the complexity sheet.

---

## 9. Testing Evidence

The DSA package was unit-tested from the CLI with `tests/dsa_test.php`:

- Stack LIFO order and undo-depth cap
- Heap extraction order and Top-K ordering
- Trie prefix matches for `sw`
- AVL range query, balance invariant, height bound
- HashMap add/get/remove, resize, collision statistics
- MergeSort, QuickSort, BubbleSort, InsertionSort all return the same order
- Multi-key `sortBy` date ordering
- Knapsack capacity DP and large-budget value DP
- Graph cycle cancellation and non-cyclic settlement flow

All tests pass. PHP lint (`php -l`) passes for every file in the project.

---

## 10. Limitations and Future Work

- The ledger and dashboard load all authorised rows into PHP before processing.
  For millions of rows, a paged or streaming pipeline would be needed. The
  current design is appropriate for a personal ledger and makes the algorithms
  visible to the examiner.
- The graph simplifier stores people by display name; a production version
  would use user ids and a friends table.
- The Knapsack fallback uses a density-greedy heuristic only for pathological
  instances that would exceed the DP cell budget; the UI labels this explicitly.
- QuickSort is implemented with median-of-three and an insertion-sort cutoff.
  It is still `O(N²)` worst case, which is shown honestly in the complexity
  table.
- Possible extensions: a segment tree for monthly rollups, an LRU cache for
  repeated dashboard loads, and a persistent audit log in place of session
  stacks.

---

## 11. Screenshot Checklist for the Report

- [ ] Dashboard: stat cards, Top-5 heap widget, DSA engine activity panel
- [ ] Transactions: Trie suggestion dropdown
- [ ] Transactions: date-range filter with “BST range active” chip
- [ ] Transactions: Undo/Redo buttons showing stack counts
- [ ] Budgets: Knapsack selected vs deferred tables
- [ ] Debts: simplified transfers after adding a cycle
- [ ] Reports: category breakdown + hash stats
- [ ] Benchmark: 1,000 rows
- [ ] Benchmark: 10,000 rows
- [ ] Benchmark: 50,000 rows
- [ ] Benchmark: search chart on log scale
- [ ] Complexity sheet screenshot
